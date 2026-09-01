<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleGmailClient
{
    public const SCOPES = 'openid email profile https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send';

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        $app = $this->appCredentials();

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $app['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $app = $this->appCredentials();
        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException($response->json('error_description') ?: 'Google did not issue an access token.');
        }

        return $response->json();
    }

    public function profile(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo');
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Unable to read the Google mailbox profile.');
        }

        return $response->json();
    }

    public function syncInbox(ChannelAccount $account): array
    {
        $meta = $account->meta_json ?? [];

        if (! empty($meta['gmail_history_id'])) {
            return $this->syncHistory($account, $meta);
        }

        return $this->initialSync($account, $meta);
    }

    public function send(
        ChannelAccount $account,
        string $to,
        string $subject,
        string $body,
        ?string $inReplyTo = null,
        ?string $threadId = null,
        array $attachments = []
    ): string {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The Gmail reply recipient is invalid.');
        }

        $from = (string) ($account->meta_json['email'] ?? '');
        $safeSubject = trim(str_replace(["\r", "\n"], '', $subject));
        $safeReference = trim(str_replace(["\r", "\n"], '', (string) $inReplyTo), '<>');
        $headers = [
            'From: '.$from,
            'To: '.$to,
            'Subject: '.mb_encode_mimeheader($safeSubject, 'UTF-8', 'B', "\r\n"),
            'MIME-Version: 1.0',
        ];
        if ($safeReference !== '') {
            $headers[] = 'In-Reply-To: <'.$safeReference.'>';
            $headers[] = 'References: <'.$safeReference.'>';
        }

        if (empty($attachments)) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: quoted-printable';
            $raw = implode("\r\n", $headers)."\r\n\r\n".quoted_printable_encode(nl2br(e($body)));
        } else {
            $boundary = '=_mail_'.md5(uniqid((string) mt_rand(), true));
            $headers[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';

            $bodyPart = "--{$boundary}\r\n"
                ."Content-Type: text/html; charset=UTF-8\r\n"
                ."Content-Transfer-Encoding: quoted-printable\r\n\r\n"
                .quoted_printable_encode(nl2br(e($body)))."\r\n";

            $attParts = '';
            foreach ($attachments as $att) {
                $rawBytes = $att['raw_bytes'] ?? (file_exists($att['path'] ?? '') ? file_get_contents($att['path']) : null);
                if ($rawBytes === null) {
                    continue;
                }
                $filename = $att['filename'] ?? 'attachment';
                $mimeType = $att['mime_type'] ?? 'application/octet-stream';
                $encodedFile = chunk_split(base64_encode($rawBytes), 76, "\r\n");

                $attParts .= "--{$boundary}\r\n"
                    ."Content-Type: {$mimeType}; name=\"".addslashes($filename)."\"\r\n"
                    ."Content-Transfer-Encoding: base64\r\n"
                    ."Content-Disposition: attachment; filename=\"".addslashes($filename)."\"\r\n\r\n"
                    .$encodedFile;
            }

            $raw = implode("\r\n", $headers)."\r\n\r\n".$bodyPart.$attParts."--{$boundary}--\r\n";
        }

        $payload = ['raw' => $this->base64UrlEncode($raw)];
        if ($threadId) {
            $payload['threadId'] = $threadId;
        }

        $response = $this->request($account)->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $payload);
        if (! $response->successful() || ! $response->json('id')) {
            throw new RuntimeException($response->json('error.message') ?: 'Google rejected the Gmail reply.');
        }

        return 'gmail:'.$response->json('id');
    }

    private function initialSync(ChannelAccount $account, array $meta): array
    {
        $response = $this->request($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', [
            'labelIds' => 'INBOX',
            'maxResults' => 50,
            'q' => 'newer_than:7d',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Gmail inbox sync failed.');
        }

        $messages = $this->fetchMessages($account, collect($response->json('messages', []))->pluck('id')->all());
        $profile = $this->request($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/profile');
        if (! $profile->successful() || ! $profile->json('historyId')) {
            throw new RuntimeException($profile->json('error.message') ?: 'Gmail did not return a synchronization cursor.');
        }
        $this->saveCursor($account, $meta, (string) $profile->json('historyId'));

        return $messages;
    }

    private function syncHistory(ChannelAccount $account, array $meta): array
    {
        $params = [
            'startHistoryId' => (string) ($meta['gmail_history_start_id'] ?? $meta['gmail_history_id']),
            'historyTypes' => 'messageAdded',
            'labelId' => 'INBOX',
            'maxResults' => 100,
        ];
        if (! empty($meta['gmail_history_page_token'])) {
            $params['pageToken'] = $meta['gmail_history_page_token'];
        }
        $response = $this->request($account)->get('https://gmail.googleapis.com/gmail/v1/users/me/history', $params);

        // Gmail expires old history cursors. A bounded recent sync safely
        // rebuilds the cursor while normal message deduplication prevents copies.
        if ($response->status() === 404) {
            $freshMeta = collect($meta)->except(['gmail_history_id', 'gmail_history_start_id', 'gmail_history_page_token'])->all();

            return $this->initialSync($account, $freshMeta);
        }
        if (! $response->successful()) {
            throw new RuntimeException($response->json('error.message') ?: 'Gmail history sync failed.');
        }

        $ids = collect($response->json('history', []))
            ->flatMap(fn (array $history) => collect($history['messagesAdded'] ?? [])->pluck('message.id'))
            ->filter()->unique()->values()->all();
        $messages = $this->fetchMessages($account, $ids);
        $nextPage = $response->json('nextPageToken');
        if ($nextPage) {
            $account->update(['meta_json' => array_merge($meta, [
                'gmail_history_start_id' => $params['startHistoryId'],
                'gmail_history_page_token' => $nextPage,
                'last_synced_at' => now()->toIso8601String(),
                'last_sync_error' => null,
            ])]);
        } else {
            $this->saveCursor($account, $meta, (string) ($response->json('historyId') ?: $params['startHistoryId']));
        }

        return $messages;
    }

    private function fetchMessages(ChannelAccount $account, array $ids): array
    {
        $messages = [];
        foreach ($ids as $id) {
            $response = $this->request($account)->get(
                'https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode((string) $id),
                ['format' => 'full'],
            );
            if ($response->status() === 404) {
                continue;
            }
            if (! $response->successful()) {
                throw new RuntimeException('Gmail message download failed: '.($response->json('error.message') ?: 'unknown error'));
            }
            $messages[] = $this->normaliseMessage($response->json());
        }

        return $messages;
    }

    private function normaliseMessage(array $message): array
    {
        $headers = collect(data_get($message, 'payload.headers', []))
            ->mapWithKeys(fn (array $header) => [strtolower((string) ($header['name'] ?? '')) => (string) ($header['value'] ?? '')]);
        [$fromName, $fromEmail] = $this->parseAddress((string) $headers->get('from', ''));
        $internalDate = (int) ($message['internalDate'] ?? 0);

        return [
            'id' => 'gmail:'.(string) $message['id'],
            'internetMessageId' => trim((string) $headers->get('message-id', ''), '<>'),
            'conversationId' => (string) ($message['threadId'] ?? $message['id']),
            'subject' => $this->decodeHeader((string) $headers->get('subject', '(no subject)')),
            'from' => ['emailAddress' => ['address' => $fromEmail, 'name' => $fromName]],
            'receivedDateTime' => $internalDate > 0 ? date(DATE_ATOM, intdiv($internalDate, 1000)) : now()->toIso8601String(),
            'bodyPreview' => (string) ($message['snippet'] ?? ''),
            'body' => ['content' => $this->messageBody((array) ($message['payload'] ?? []))],
            'isRead' => ! in_array('UNREAD', $message['labelIds'] ?? [], true),
            'hasAttachments' => $this->hasAttachments((array) ($message['payload'] ?? [])),
        ];
    }

    private function messageBody(array $part): string
    {
        $mime = strtolower((string) ($part['mimeType'] ?? ''));
        $data = data_get($part, 'body.data');
        if (is_string($data) && $data !== '' && in_array($mime, ['text/plain', 'text/html'], true)) {
            return $this->base64UrlDecode($data);
        }
        $parts = (array) ($part['parts'] ?? []);
        foreach (['text/plain', 'text/html'] as $preferred) {
            foreach ($parts as $child) {
                if (strtolower((string) ($child['mimeType'] ?? '')) === $preferred) {
                    $body = $this->messageBody($child);
                    if ($body !== '') {
                        return $body;
                    }
                }
            }
        }
        foreach ($parts as $child) {
            $body = $this->messageBody($child);
            if ($body !== '') {
                return $body;
            }
        }

        return '';
    }

    private function hasAttachments(array $part): bool
    {
        if (! empty($part['filename'])) {
            return true;
        }

        return collect($part['parts'] ?? [])->contains(fn (array $child) => $this->hasAttachments($child));
    }

    private function parseAddress(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(?:"?([^"<]*)"?\s*)?<([^>]+)>$/u', $value, $matches)) {
            return [trim($this->decodeHeader($matches[1])), strtolower(trim($matches[2]))];
        }

        return ['', strtolower($value)];
    }

    private function decodeHeader(string $value): string
    {
        return function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($value) : $value;
    }

    private function saveCursor(ChannelAccount $account, array $meta, string $historyId): void
    {
        $account->update(['meta_json' => array_merge(
            collect($meta)->except(['gmail_history_start_id', 'gmail_history_page_token'])->all(),
            ['gmail_history_id' => $historyId, 'last_synced_at' => now()->toIso8601String(), 'last_sync_error' => null],
        )]);
    }

    private function request(ChannelAccount $account): PendingRequest
    {
        return Http::withToken($this->accessToken($account))->acceptJson()->timeout(30);
    }

    private function accessToken(ChannelAccount $account): string
    {
        return Cache::lock('google-mail-token:'.$account->id, 15)->block(5, function () use ($account): string {
            $account->refresh();
            $credentials = $account->credentials ?? [];
            if (! empty($credentials['access_token']) && now()->addMinute()->lt($credentials['expires_at'] ?? now()->subMinute())) {
                return $credentials['access_token'];
            }
            if (empty($credentials['refresh_token'])) {
                throw new RuntimeException('Google mailbox authorization has expired. Reconnect the account.');
            }

            $app = $this->appCredentials();
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => $app['client_id'],
                'client_secret' => $app['client_secret'],
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);
            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException($response->json('error_description') ?: 'Google token refresh failed.');
            }

            $tokens = $response->json();
            $credentials = array_merge($credentials, [
                'access_token' => $tokens['access_token'],
                'expires_at' => now()->addSeconds(max(60, ((int) ($tokens['expires_in'] ?? 3600)) - 60))->toIso8601String(),
            ]);
            $account->update(['credentials' => $credentials]);

            return $credentials['access_token'];
        });
    }

    private function appCredentials(): array
    {
        $config = IntegrationConfig::forProvider('oauth_google_mail');
        $credentials = $config?->credentials ?? [];
        if (! $config?->enabled || empty($credentials['client_id']) || empty($credentials['client_secret'])) {
            throw new RuntimeException('Google Gmail OAuth is not configured by the system administrator.');
        }

        return [
            'client_id' => trim((string) $credentials['client_id']),
            'client_secret' => (string) $credentials['client_secret'],
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
