<?php

namespace App\Modules\Integrations\Services;

use App\Modules\AI\Services\ProviderErrorPresenter;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Services\StorageManager;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http as HttpFacade;
use Illuminate\Support\Facades\Storage;

class ConnectionTester
{
    public function test(IntegrationConfig $config): array
    {
        try {
            $result = match (true) {
                $config->provider === 'meta_app' => $this->testMeta($config),
                str_starts_with($config->provider, 'oauth_') => $this->testOAuth($config),
                str_starts_with($config->provider, 'llm_') => $this->testLlm($config),
                str_starts_with($config->provider, 'sms_') => $this->testSms($config),
                $config->provider === 'google_workspace' => $this->testGoogleWorkspace($config),
                $config->provider === 'onesignal' => $this->testOneSignal($config),
                $config->provider === 'qdrant' => $this->testQdrant($config),
                str_starts_with($config->provider, 'storage_') => $this->testStorage($config),
                default => ['ok' => false, 'message' => 'No test available for this provider.'],
            };
        } catch (\Throwable $e) {
            if (str_starts_with($config->provider, 'llm_')) {
                $error = ProviderErrorPresenter::present($e);
                $result = ['ok' => false, 'message' => $error['message']];
            } else {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        $config->update([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'fail',
            'last_test_message' => $result['message'] ?? null,
        ]);

        return $result;
    }

    private function testMeta(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $appId = $creds['app_id'] ?? '';
        $appSecret = $creds['app_secret'] ?? '';
        if (empty($appId) || empty($appSecret)) {
            return ['ok' => false, 'message' => 'App ID and App Secret are required.'];
        }

        // Validate the app credentials themselves even when the optional system
        // user token is intentionally left blank (OAuth social connections do not
        // require a system user token).
        $appResp = HttpFacade::timeout(10)
            ->withToken($appId.'|'.$appSecret)
            ->get('https://graph.facebook.com/v25.0/app', ['fields' => 'id,name']);
        if (! $appResp->successful() || ! isset($appResp->json()['id'])) {
            return ['ok' => false, 'message' => $appResp->json()['error']['message'] ?? 'Meta app credentials are invalid.'];
        }

        // If configured, also validate the system-user token used by WhatsApp and
        // embedded signup. This prevents an admin from seeing green while the
        // messaging token is already expired or missing permissions.
        $systemToken = $creds['system_user_token'] ?? '';
        if ($systemToken !== '') {
            $tokenResp = HttpFacade::timeout(10)
                ->withToken($systemToken)
                ->get('https://graph.facebook.com/v25.0/me', ['fields' => 'id']);
            if (! $tokenResp->successful() || ! isset($tokenResp->json()['id'])) {
                return ['ok' => false, 'message' => $tokenResp->json()['error']['message'] ?? 'Meta system user token is invalid.'];
            }
        }

        return ['ok' => true, 'message' => 'Meta app credentials are valid.'.($systemToken !== '' ? ' System user token is valid.' : '')];
    }

    private function testOAuth(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $id = $creds['client_id'] ?? $creds['client_key'] ?? '';
        $secret = $creds['client_secret'] ?? '';
        if (empty($id) || empty($secret)) {
            return ['ok' => false, 'message' => 'Client ID and Secret are required.'];
        }

        return [
            'ok' => false,
            'message' => 'Credential presence confirmed, but this provider cannot validate an OAuth client secret without a real user authorization. Complete one sandbox connect flow before enabling it for customers.',
        ];
    }

    private function testLlm(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $apiKey = $creds['api_key'] ?? '';
        if (empty($apiKey)) {
            return ['ok' => false, 'message' => 'API key is required.'];
        }

        if (str_contains($config->provider, 'openai')) {
            $request = HttpFacade::timeout(15)->withToken($apiKey);
            if (! empty($creds['organization_id'])) {
                $request = $request->withHeaders(['OpenAI-Organization' => $creds['organization_id']]);
            }
            $resp = $request
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                    'max_tokens' => 1,
                ]);

            if (! $resp->successful()) {
                return $this->llmFailure('OpenAI chat test failed.', $resp);
            }

            $embeddingResp = $request->post('https://api.openai.com/v1/embeddings', [
                'model' => 'text-embedding-3-small',
                'input' => ['connection test'],
            ]);

            if (! $embeddingResp->successful()) {
                return $this->llmFailure('OpenAI chat works, but Knowledge Base embeddings failed.', $embeddingResp);
            }

            return ['ok' => true, 'message' => 'OpenAI chat and Knowledge Base embeddings connected successfully.'];
        }

        if (str_contains($config->provider, 'anthropic')) {
            $resp = HttpFacade::timeout(15)
                ->withHeaders(['x-api-key' => $apiKey, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => 'claude-haiku-4-5-20251001',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]);

            return $resp->successful()
                ? ['ok' => true, 'message' => 'Anthropic chat connected. Knowledge Bases still require an enabled OpenAI or Gemini provider for embeddings.']
                : $this->llmFailure('Anthropic chat test failed.', $resp);
        }

        if (str_contains($config->provider, 'gemini')) {
            // Exercise the same generation endpoint and model used at runtime.
            // Listing models can succeed for a key that still cannot call the
            // selected model because of billing/region/project restrictions.
            $resp = HttpFacade::timeout(15)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent', [
                    'contents' => [['parts' => [['text' => 'hi']]]],
                    'generationConfig' => ['maxOutputTokens' => 1],
                ]);

            if (! $resp->successful()) {
                return $this->llmFailure('Gemini chat test failed.', $resp);
            }

            $embeddingResp = HttpFacade::timeout(15)
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents', [
                    'requests' => [[
                        'model' => 'models/gemini-embedding-2',
                        'content' => ['parts' => [['text' => 'connection test']]],
                    ]],
                ]);

            if (! $embeddingResp->successful()) {
                return $this->llmFailure('Gemini chat works, but Knowledge Base embeddings failed.', $embeddingResp);
            }

            return ['ok' => true, 'message' => 'Gemini chat and Knowledge Base embeddings connected successfully.'];
        }

        return ['ok' => false, 'message' => 'Unknown LLM provider.'];
    }

    private function llmFailure(string $stage, ClientResponse $response): array
    {
        $exception = new \RuntimeException(
            'AI provider request failed with HTTP '.$response->status().': '.$response->body()
        );
        $error = ProviderErrorPresenter::present($exception);

        return ['ok' => false, 'message' => $stage.' '.$error['message']];
    }

    private function testSms(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];

        if (str_contains($config->provider, 'twilio')) {
            $sid = $creds['account_sid'] ?? '';
            $token = $creds['auth_token'] ?? '';
            if (empty($sid) || empty($token)) {
                return ['ok' => false, 'message' => 'Account SID and Auth Token required.'];
            }
            $resp = HttpFacade::timeout(10)->withBasicAuth($sid, $token)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$sid}.json");

            return $resp->successful()
                ? ['ok' => true,  'message' => 'Twilio account connected: '.$resp->json()['friendly_name']]
                : ['ok' => false, 'message' => $resp->json()['message'] ?? 'Twilio error.'];
        }

        if (str_contains($config->provider, 'nexmo')) {
            $key = $creds['api_key'] ?? '';
            $secret = $creds['api_secret'] ?? '';
            if (empty($key) || empty($secret)) {
                return ['ok' => false, 'message' => 'API Key and Secret required.'];
            }
            $resp = HttpFacade::timeout(10)
                ->get('https://rest.nexmo.com/account/get-balance', ['api_key' => $key, 'api_secret' => $secret]);

            return $resp->successful()
                ? ['ok' => true,  'message' => 'Vonage balance: '.$resp->json()['value'].' EUR']
                : ['ok' => false, 'message' => $resp->json()['error-text'] ?? 'Vonage error.'];
        }

        if (str_contains($config->provider, 'messagebird')) {
            $key = $creds['api_key'] ?? '';
            if (empty($key)) {
                return ['ok' => false, 'message' => 'API Key required.'];
            }
            $resp = HttpFacade::timeout(10)
                ->withToken($key)
                ->get('https://rest.messagebird.com/balance');

            return $resp->successful()
                ? ['ok' => true,  'message' => 'MessageBird balance: '.$resp->json()['amount'].' '.$resp->json()['type']]
                : ['ok' => false, 'message' => 'MessageBird error.'];
        }

        // For SMSBD and REVE: just validate keys are present
        $key = $creds['api_key'] ?? '';

        return empty($key)
            ? ['ok' => false, 'message' => 'API key is required.']
            : ['ok' => true,  'message' => 'Credentials are present. Live test requires sending a message.'];
    }

    private function testGoogleWorkspace(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        if (empty($creds['client_id']) || empty($creds['client_secret']) || empty($creds['refresh_token'])) {
            return ['ok' => false, 'message' => 'Client ID, Client Secret and Refresh Token are required.'];
        }

        // Exchange the refresh token for an access token — proves the grant is valid.
        $resp = HttpFacade::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'refresh_token' => $creds['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        return $resp->successful() && $resp->json('access_token')
            ? ['ok' => true,  'message' => 'Google Workspace connection successful.']
            : ['ok' => false, 'message' => $resp->json('error_description') ?? $resp->json('error') ?? 'Google token exchange failed.'];
    }

    private function testOneSignal(IntegrationConfig $config): array
    {
        $credentials = $config->credentials ?? [];
        $appId = trim((string) ($credentials['app_id'] ?? ''));
        $apiKey = trim((string) ($credentials['rest_api_key'] ?? ''));

        if ($appId === '' || $apiKey === '') {
            return ['ok' => false, 'message' => 'OneSignal App ID and REST API Key are required.'];
        }

        // Validate the App API key without targeting any user. This must never
        // send a configuration test to a Super Admin account.
        $response = HttpFacade::timeout(15)
            ->withHeaders(['Authorization' => 'Key '.$apiKey])
            ->get('https://api.onesignal.com/apps/'.$appId.'/segments', ['limit' => 1]);

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $response->json('errors.0') ?? $response->json('errors') ?? 'OneSignal rejected the credentials or notification request.'];
        }

        return ['ok' => true, 'message' => 'OneSignal App ID and API key are valid. No push notification was sent.'];
    }

    private function testQdrant(IntegrationConfig $config): array
    {
        $creds = $config->credentials ?? [];
        $url = rtrim($creds['url'] ?? '', '/');
        $key = $creds['api_key'] ?? '';
        if (empty($url)) {
            return ['ok' => false, 'message' => 'Qdrant URL is required.'];
        }
        $headers = $key ? ['api-key' => $key] : [];
        $resp = HttpFacade::timeout(10)->withHeaders($headers)->get($url.'/collections');

        return $resp->successful()
            ? ['ok' => true,  'message' => 'Qdrant connected. Collections: '.count($resp->json()['result']['collections'] ?? [])]
            : ['ok' => false, 'message' => 'Qdrant error: '.$resp->status()];
    }

    private function testStorage(IntegrationConfig $config): array
    {
        if ($config->provider === 'storage_local') {
            $disk = Storage::disk('public');
            $testPath = '.storage-test/ping.txt';
            $disk->put($testPath, 'ok');
            $exists = $disk->exists($testPath);
            $disk->delete($testPath);

            return $exists
                ? ['ok' => true,  'message' => 'Local (public) disk is writable.']
                : ['ok' => false, 'message' => 'Could not write to local public disk.'];
        }

        $creds = $config->credentials ?? [];
        $key = $creds['key'] ?? '';
        $secret = $creds['secret'] ?? '';
        $bucket = $creds['bucket'] ?? '';

        if (empty($key) || empty($secret) || empty($bucket)) {
            return ['ok' => false, 'message' => 'Access key, secret, and bucket are required.'];
        }

        // Temporarily wire credentials into the disk config
        $manager = app(StorageManager::class);
        $manager->clearCache();

        $diskName = IntegrationConfig::STORAGE_DISK_MAP[$config->provider] ?? null;
        if (! $diskName) {
            return ['ok' => false, 'message' => 'Unknown storage provider.'];
        }

        $diskCfg = [
            'driver' => 's3',
            'key' => $key,
            'secret' => $secret,
            'region' => $creds['region'] ?? 'us-east-1',
            'bucket' => $bucket,
            'url' => $creds['url'] ?? null,
            'endpoint' => $creds['endpoint'] ?? null,
            'use_path_style_endpoint' => false,
            'throw' => true,
            'visibility' => 'public',
            'options' => ['ACL' => 'public-read'],
        ];

        Config::set("filesystems.disks.{$diskName}", $diskCfg);
        Storage::forgetDisk($diskName);

        try {
            $prefix = trim($creds['directory_prefix'] ?? '', '/');
            $testPath = ($prefix !== '' ? $prefix.'/' : '').'.storage-test/ping.txt';
            $disk = Storage::disk($diskName);
            $disk->put($testPath, 'ok', 'public');
            $exists = $disk->exists($testPath);
            $disk->delete($testPath);

            return $exists
                ? ['ok' => true,  'message' => 'Connected to bucket "'.$bucket.'"'.($prefix ? " (prefix: {$prefix})" : '').'.']
                : ['ok' => false, 'message' => 'Write test failed — check bucket permissions.'];
        } catch (\Throwable $e) {
            $cause = $e->getPrevious();
            $detail = $cause ? ' ('.$cause->getMessage().')' : '';

            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage().$detail];
        }
    }
}
