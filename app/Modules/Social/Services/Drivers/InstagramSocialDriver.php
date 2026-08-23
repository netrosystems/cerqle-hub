<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class InstagramSocialDriver implements DeletesPublishedPosts, SocialNetworkInterface
{
    public function network(): string
    {
        return 'instagram';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $response = Http::timeout(15)->get('https://graph.instagram.com/me', [
            'fields' => 'id,name,profile_picture_url',
            'access_token' => $accessToken,
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('Instagram profile lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();
        if (empty($res['id'])) {
            throw new \RuntimeException('Instagram returned no account identity.');
        }

        return [
            'account_id' => $res['id'] ?? '',
            'name' => $res['name'] ?? '',
            'picture_url' => $res['profile_picture_url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $igUserId = $account->account_id;
        $token = $account->access_token;

        // Step 1: Create media container
        $containerPayload = ['caption' => $postData['body'] ?? '', 'access_token' => $token];
        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => $u !== null && $u !== ''));
        if (! empty($mediaUrls)) {
            $containerPayload['image_url'] = $mediaUrls[0];
        } else {
            throw new \RuntimeException('Instagram posts require at least one image.');
        }

        $container = Http::post("https://graph.facebook.com/v25.0/{$igUserId}/media", $containerPayload)->json();
        $creationId = $container['id'] ?? null;
        if (! $creationId) {
            throw new \RuntimeException('Instagram container creation failed: '.json_encode($container));
        }

        // Step 2: Publish
        $res = Http::post("https://graph.facebook.com/v25.0/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ])->json();

        return $res['id'] ?? throw new \RuntimeException('Instagram publish failed: '.json_encode($res));
    }

    public function permalink(SocialAccount $account, string $platformPostId): ?string
    {
        $response = Http::timeout(15)->get("https://graph.facebook.com/v25.0/{$platformPostId}", [
            'fields' => 'permalink',
            'access_token' => $account->access_token,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $permalink = $response->json('permalink');

        return is_string($permalink) && str_starts_with($permalink, 'https://www.instagram.com/')
            ? $permalink
            : null;
    }

    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void
    {
        $response = Http::timeout(20)
            ->asForm()
            ->delete($this->objectUrl($platformPostId), [
                'access_token' => $account->access_token,
            ]);

        $this->assertDeleteSucceeded($response);
    }

    private function objectUrl(string $platformPostId): string
    {
        if ($platformPostId === '' || ! preg_match('/^[A-Za-z0-9_:\-]+$/', $platformPostId)) {
            throw new \InvalidArgumentException('Instagram returned an invalid media ID.');
        }

        return 'https://graph.facebook.com/v25.0/'.rawurlencode($platformPostId);
    }

    private function assertDeleteSucceeded(Response $response): void
    {
        $payload = $response->json();
        $success = $response->successful()
            && ($payload === true || data_get($payload, 'success') === true);

        if ($success) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? 'Unknown Graph API error.');
        $code = $response->json('error.code');

        throw new \RuntimeException(sprintf(
            'Instagram delete failed%s: %s',
            $code !== null ? " (Meta code {$code})" : '',
            $message
        ));
    }
}
