<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftGraphMailClient
{
    public const SCOPES = 'openid profile email offline_access User.Read Mail.ReadWrite Mail.Send';

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $app = $this->appCredentials();
        $tenant = $app['tenant'] ?: 'common';

        return 'https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => $app['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $app = $this->appCredentials();
        $response = Http::asForm()->timeout(20)->post(
            'https://login.microsoftonline.com/'.rawurlencode($app['tenant'] ?: 'common').'/oauth2/v2.0/token',
            [
                'client_id' => $app['client_id'],
                'client_secret' => $app['client_secret'],
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
                'scope' => self::SCOPES,
            ],
        );

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException($response->json('error_description') ?: 'Microsoft did not issue an access token.');
        }

        return $response->json();
    }

    public function profile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->timeout(15)->get('https://graph.microsoft.com/v1.0/me', [
            '$select' => 'id,displayName,mail,userPrincipalName',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Unable to read the Microsoft mailbox profile.');
        }

        return $response->json();
    }

    public function syncInbox(ChannelAccount $account): array
    {
        $meta = $account->meta_json ?? [];
        $url = $meta['delta_link'] ?? 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?'.http_build_query([
            '$select' => 'id,internetMessageId,conversationId,subject,from,toRecipients,receivedDateTime,sentDateTime,bodyPreview,body,isRead,hasAttachments',
            '$top' => 50,
        ]);
        $messages = [];
        $pages = 0;
        $delta = null;

        do {
            $response = $this->request($account)->get($url);
            if (! $response->successful()) {
                throw new RuntimeException($response->json('error.message') ?: 'Microsoft mailbox sync failed.');
            }
            $messages = array_merge($messages, $response->json('value', []));
            $url = $response->json('@odata.nextLink');
            $delta = $response->json('@odata.deltaLink');
            $pages++;
        } while ($url && $pages < 10);

        // A large mailbox may need more than ten API pages. Persist the nextLink
        // as a continuation cursor so the next scheduled job resumes instead of
        // downloading the first pages repeatedly; the final round replaces it
        // with the long-lived deltaLink.
        $cursor = $delta ?: $url;
        if (! empty($cursor)) {
            $account->update(['meta_json' => array_merge($meta, [
                'delta_link' => $cursor,
                'last_synced_at' => now()->toIso8601String(),
                'last_sync_error' => null,
            ])]);
        }

        return $messages;
    }

    public function sendReply(ChannelAccount $account, string $messageId, string $body): string
    {
        $response = $this->request($account)->post(
            'https://graph.microsoft.com/v1.0/me/messages/'.rawurlencode($messageId).'/reply',
            ['comment' => $body],
        );
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Microsoft rejected the email reply.');
        }

        return 'graph:'.bin2hex(random_bytes(12));
    }

    public function sendMessage(
        ChannelAccount $account,
        string $to,
        string $subject,
        string $body,
        array $cc = [],
        array $bcc = [],
        array $attachments = []
    ): string {
        $recipients = fn (array $addresses): array => array_map(
            fn (string $address) => ['emailAddress' => ['address' => $address]],
            $addresses,
        );

        $graphAttachments = [];
        foreach ($attachments as $att) {
            $rawBytes = $att['raw_bytes'] ?? (file_exists($att['path'] ?? '') ? file_get_contents($att['path']) : null);
            if ($rawBytes === null) {
                continue;
            }
            $graphAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $att['filename'] ?? 'attachment',
                'contentType' => $att['mime_type'] ?? 'application/octet-stream',
                'contentBytes' => base64_encode($rawBytes),
            ];
        }

        $messagePayload = [
            'subject' => $subject,
            'body' => ['contentType' => 'HTML', 'content' => nl2br(e($body))],
            'toRecipients' => $recipients([$to]),
            'ccRecipients' => $recipients($cc),
            'bccRecipients' => $recipients($bcc),
        ];
        if (! empty($graphAttachments)) {
            $messagePayload['attachments'] = $graphAttachments;
        }

        $response = $this->request($account)->post('https://graph.microsoft.com/v1.0/me/sendMail', [
            'message' => $messagePayload,
            'saveToSentItems' => true,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Microsoft rejected the email dispatch.');
        }

        return 'graph:'.bin2hex(random_bytes(12));
    }

    public function verify(ChannelAccount $account): bool
    {
        return $this->request($account)->get('https://graph.microsoft.com/v1.0/me?$select=id')->successful();
    }

    private function request(ChannelAccount $account): PendingRequest
    {
        return Http::withToken($this->accessToken($account))->acceptJson()->timeout(30);
    }

    private function accessToken(ChannelAccount $account): string
    {
        return Cache::lock('microsoft-mail-token:'.$account->id, 15)->block(5, function () use ($account): string {
            $account->refresh();
            $credentials = $account->credentials ?? [];
            if (! empty($credentials['access_token']) && now()->addMinute()->lt($credentials['expires_at'] ?? now()->subMinute())) {
                return $credentials['access_token'];
            }
            if (empty($credentials['refresh_token'])) {
                throw new RuntimeException('Microsoft mailbox authorization has expired. Reconnect the account.');
            }

            $app = $this->appCredentials();
            $response = Http::asForm()->timeout(20)->post(
                'https://login.microsoftonline.com/'.rawurlencode($app['tenant'] ?: 'common').'/oauth2/v2.0/token',
                [
                    'client_id' => $app['client_id'],
                    'client_secret' => $app['client_secret'],
                    'refresh_token' => $credentials['refresh_token'],
                    'grant_type' => 'refresh_token',
                    'scope' => self::SCOPES,
                ],
            );
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException($response->json('error_description') ?: 'Microsoft token refresh failed.');
            }

            $tokens = $response->json();
            $credentials = array_merge($credentials, [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? $credentials['refresh_token'],
                'expires_at' => now()->addSeconds(max(60, ((int) ($tokens['expires_in'] ?? 3600)) - 60))->toIso8601String(),
            ]);
            $account->update(['credentials' => $credentials]);

            return $credentials['access_token'];
        });
    }

    private function appCredentials(): array
    {
        $config = IntegrationConfig::forProvider('oauth_microsoft_365');
        $credentials = $config?->credentials ?? [];
        if (! $config?->enabled || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new RuntimeException('Microsoft 365 OAuth is not configured by the system administrator.');
        }

        return [
            'client_id' => trim((string) $credentials['client_id']),
            'client_secret' => (string) $credentials['client_secret'],
            'tenant' => trim((string) ($credentials['tenant'] ?? 'common')) ?: 'common',
        ];
    }
}
