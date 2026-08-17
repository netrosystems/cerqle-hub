<?php

namespace App\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneSignalService
{
    private const API_URL = 'https://api.onesignal.com/notifications';

    public function isConfigured(): bool
    {
        $config = $this->configuration();

        return $config['enabled'] && $config['app_id'] !== '' && $config['rest_api_key'] !== '';
    }

    /** The only OneSignal value that may be rendered in the browser. */
    public function publicAppId(): string
    {
        $config = $this->configuration();

        return $config['enabled'] ? $config['app_id'] : '';
    }

    /**
     * Send a push notification to a user identified by their Laravel user ID.
     */
    public function sendToUser(int|string $userId, string $title, string $body, ?string $url = null, int|string|null $conversationId = null, array $data = []): void
    {
        $this->sendToExternalId('user:'.$userId, $title, $body, $url, $conversationId, $data);
    }

    /** Send a push message to a namespaced OneSignal external ID. */
    public function sendToExternalId(string $externalId, string $title, string $body, ?string $url = null, int|string|null $conversationId = null, array $data = []): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $config = $this->configuration();

        $payload = [
            'app_id' => $config['app_id'],
            'include_aliases' => ['external_id' => [$externalId]],
            'target_channel' => 'push',
            'headings' => ['en' => $title],
            'contents' => ['en' => $body],
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
        ];

        if ($url) {
            // Keep browser push navigation separate from native app routing.
            // Native clients use the custom data payload below so they can open
            // the correct in-app screen instead of launching the web browser.
            $payload['web_url'] = $url;
        }

        // Collapse multiple notifications for the same conversation into one.
        if ($conversationId !== null) {
            $payload['collapse_id'] = "conversation-{$conversationId}";
            $payload['web_push_topic'] = "conversation-{$conversationId}";
            $data['conversation_id'] = $conversationId;
        }

        $data['url'] = $url;
        $payload['data'] = array_filter($data, fn ($value) => $value !== null && $value !== '');

        $response = Http::withHeaders(['Authorization' => 'Key '.$config['rest_api_key']])
            ->post(self::API_URL, $payload);

        if (! $response->successful()) {
            Log::warning('OneSignal notification failed', [
                'external_id' => $externalId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Send to multiple users at once.
     *
     * @param  array<int|string>  $userIds
     */
    public function sendToUsers(array $userIds, string $title, string $body, ?string $url = null): void
    {
        if (! $this->isConfigured() || empty($userIds)) {
            return;
        }

        $config = $this->configuration();

        $payload = [
            'app_id' => $config['app_id'],
            'include_aliases' => ['external_id' => array_map(fn ($id) => 'user:'.$id, $userIds)],
            'target_channel' => 'push',
            'headings' => ['en' => $title],
            'contents' => ['en' => $body],
        ];

        if ($url) {
            $payload['url'] = $url;
        }

        $response = Http::withHeaders(['Authorization' => 'Key '.$config['rest_api_key']])
            ->post(self::API_URL, $payload);

        if (! $response->successful()) {
            Log::warning('OneSignal batch notification failed', [
                'user_ids' => $userIds,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
    }

    /**
     * Super-admin configuration overrides .env, including an explicit disabled
     * state. The environment remains a safe fallback for existing deployments.
     *
     * @return array{enabled: bool, app_id: string, rest_api_key: string}
     */
    private function configuration(): array
    {
        try {
            $saved = IntegrationConfig::forProvider('onesignal');
            if ($saved) {
                $credentials = $saved->credentials ?? [];

                return [
                    'enabled' => (bool) $saved->enabled,
                    'app_id' => trim((string) ($credentials['app_id'] ?? '')),
                    'rest_api_key' => trim((string) ($credentials['rest_api_key'] ?? '')),
                ];
            }
        } catch (\Throwable) {
            // During install/migrations integration_configs may not exist yet.
        }

        return [
            'enabled' => filled(config('services.onesignal.app_id', '')),
            'app_id' => trim((string) config('services.onesignal.app_id', '')),
            'rest_api_key' => trim((string) config('services.onesignal.rest_api_key', '')),
        ];
    }
}
