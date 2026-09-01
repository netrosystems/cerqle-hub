<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokDriver implements ChecksPublishProcessing, SocialNetworkInterface
{
    public function network(): string
    {
        return 'tiktok';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,avatar_url');
        if (! $response->successful()) {
            throw new \RuntimeException('TikTok profile lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();
        $user = $res['data']['user'] ?? [];

        if (empty($user['open_id'])) {
            throw new \RuntimeException('TikTok returned no creator identity.');
        }

        return [
            'account_id' => $user['open_id'] ?? '',
            'name' => $user['display_name'] ?? '',
            'picture_url' => $user['avatar_url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $options = (array) ($postData['tiktok_options'] ?? []);
        if (! ($options['consent'] ?? false)) {
            throw new \RuntimeException('TikTok publishing requires explicit user consent.');
        }

        $videoUrl = $postData['media_urls'][0] ?? null;
        if (! is_string($videoUrl) || ! filter_var($videoUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('TikTok publishing requires one publicly reachable HTTPS video URL.');
        }
        if (parse_url($videoUrl, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('TikTok video URL must use HTTPS.');
        }

        $res = Http::withToken($account->access_token)
            ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'post_info' => [
                    'title' => $postData['body'] ?? ($postData['title'] ?? ''),
                    'privacy_level' => $options['privacy_level'] ?? 'SELF_ONLY',
                    'disable_duet' => ! (bool) ($options['allow_duet'] ?? false),
                    'disable_comment' => ! (bool) ($options['allow_comment'] ?? false),
                    'disable_stitch' => ! (bool) ($options['allow_stitch'] ?? false),
                    'video_cover_timestamp_ms' => max(0, (int) ($options['video_cover_timestamp_ms'] ?? 0)),
                    'brand_content_toggle' => (bool) ($options['brand_content'] ?? false),
                    'brand_organic_toggle' => (bool) ($options['brand_organic'] ?? false),
                    'is_aigc' => (bool) ($options['is_aigc'] ?? false),
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $videoUrl,
                ],
            ])->json();

        $publishId = $res['data']['publish_id'] ?? null;
        if (! $publishId) {
            throw new \RuntimeException('TikTok publish failed: '.json_encode($res));
        }

        // Do a short poll (3 attempts × 5 s = 15 s max) to capture the post URL quickly.
        // If TikTok hasn't finished processing in time, the publish_id is still stored
        // so a follow-up job could resolve it later.
        $postUrl = $this->pollPublishStatus($account->access_token, $publishId, maxAttempts: 3);
        if ($postUrl && ! empty($postData['id'])) {
            SocialPost::where('id', $postData['id'])->update(['provider_post_id' => $publishId, 'post_url' => $postUrl]);
        }

        Log::info('TikTok video published', ['publish_id' => $publishId, 'url' => $postUrl]);

        return $publishId;
    }

    /** @return array<string, mixed> */
    public function creatorOptions(SocialAccount $account): array
    {
        $response = Http::withToken($account->access_token)
            ->timeout(15)
            ->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/');

        if (! $response->successful() || data_get($response->json(), 'error.code') !== 'ok') {
            throw new \RuntimeException('TikTok creator publishing options could not be loaded.');
        }

        $data = (array) data_get($response->json(), 'data', []);

        return [
            'privacy_level_options' => array_values((array) ($data['privacy_level_options'] ?? [])),
            'comment_disabled' => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($data['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => (int) ($data['max_video_post_duration_sec'] ?? 0),
        ];
    }

    public function checkPublishProcessing(SocialAccount $account, string $platformPostId): array
    {
        $response = Http::withToken($account->access_token)
            ->timeout(20)
            ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
                'publish_id' => $platformPostId,
            ]);

        if (! $response->successful() || data_get($response->json(), 'error.code') !== 'ok') {
            throw new \RuntimeException('TikTok processing lookup failed.');
        }

        $status = (string) data_get($response->json(), 'data.status', '');
        if (in_array($status, ['FAILED', 'CANCELED'], true)) {
            return ['status' => 'failed', 'error' => 'TikTok could not process the uploaded video.'];
        }
        if ($status !== 'PUBLISH_COMPLETE') {
            return ['status' => 'processing'];
        }

        $postId = data_get($response->json(), 'data.publicly_available_post_id.0');

        return array_filter([
            'status' => 'published',
            'url' => $postId ? "https://www.tiktok.com/@me/video/{$postId}" : null,
        ]);
    }

    private function pollPublishStatus(string $accessToken, string $publishId, int $maxAttempts = 3): ?string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(5);
            $res = Http::withToken($accessToken)
                ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', ['publish_id' => $publishId])
                ->json();
            $status = $res['data']['status'] ?? '';
            $postId = $res['data']['publicly_available_post_id'][0] ?? null;

            if ($status === 'PUBLISH_COMPLETE' && $postId) {
                return "https://www.tiktok.com/@me/video/{$postId}";
            }

            if (in_array($status, ['FAILED', 'CANCELED'])) {
                Log::warning('TikTok publish failed in poll', ['status' => $status, 'publish_id' => $publishId]);

                return null;
            }
        }

        return null;
    }
}
