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

        $mediaUrls = array_values(array_filter($postData['media_urls'] ?? [], fn ($u) => $u !== null && $u !== ''));
        if ($mediaUrls === []) {
            throw new \RuntimeException('Instagram posts require at least one image.');
        }

        // Step 1: Create a single image/Reel container or carousel children.
        if (count($mediaUrls) === 1) {
            $containerPayload = ['caption' => $postData['body'] ?? '', 'access_token' => $token];
            if ($this->isVideoUrl($mediaUrls[0])) {
                $containerPayload += ['media_type' => 'REELS', 'video_url' => $mediaUrls[0], 'share_to_feed' => true];
            } else {
                $containerPayload['image_url'] = $mediaUrls[0];
            }

            $creationId = $this->createContainer($igUserId, $containerPayload);
            if ($this->isVideoUrl($mediaUrls[0])) {
                $this->waitForContainer($creationId, $token);
            }
        } else {
            if (count($mediaUrls) > 10) {
                throw new \RuntimeException('Instagram carousels support at most 10 media items.');
            }
            $children = [];
            foreach ($mediaUrls as $url) {
                $child = ['is_carousel_item' => true, 'access_token' => $token];
                if ($this->isVideoUrl($url)) {
                    $child += ['media_type' => 'VIDEO', 'video_url' => $url];
                } else {
                    $child['image_url'] = $url;
                }
                $childId = $this->createContainer($igUserId, $child);
                if ($this->isVideoUrl($url)) {
                    $this->waitForContainer($childId, $token);
                }
                $children[] = $childId;
            }
            $creationId = $this->createContainer($igUserId, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $children),
                'caption' => $postData['body'] ?? '',
                'access_token' => $token,
            ]);
        }

        // Step 2: Publish
        $res = Http::post("https://graph.facebook.com/v25.0/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ])->json();

        return $res['id'] ?? throw new \RuntimeException('Instagram publish failed: '.json_encode($res));
    }

    private function createContainer(string $igUserId, array $payload): string
    {
        $container = Http::timeout(60)->post("https://graph.facebook.com/v25.0/{$igUserId}/media", $payload)->json();

        return $container['id'] ?? throw new \RuntimeException('Instagram container creation failed: '.json_encode($container));
    }

    private function waitForContainer(string $creationId, string $token): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            sleep(5);
            $response = Http::timeout(20)->get("https://graph.facebook.com/v25.0/{$creationId}", [
                'fields' => 'status_code,status',
                'access_token' => $token,
            ]);
            $status = (string) ($response->json('status_code') ?? '');
            if ($status === 'FINISHED') {
                return;
            }
            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new \RuntimeException('Instagram could not process the uploaded video container.');
            }
        }

        throw new \RuntimeException('Instagram video processing did not complete in time.');
    }

    private function isVideoUrl(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|mov|webm|m4v)(?:\?|$)/i', $url);
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
