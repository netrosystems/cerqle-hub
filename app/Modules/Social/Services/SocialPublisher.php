<?php

namespace App\Modules\Social\Services;

use App\Models\Media;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Jobs\ConfirmSocialPostProcessingJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\ChecksPublishProcessing;
use App\Modules\Social\Services\Drivers\DeletesPublishedPosts;
use App\Modules\Social\Services\Drivers\EditsPublishedPosts;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\SocialNetworkInterface;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use Illuminate\Support\Facades\Log;

class SocialPublisher
{
    /** @var array<string, SocialNetworkInterface> */
    private array $drivers;

    public function __construct(
        private readonly SocialMediaLifecycleService $mediaLifecycle,
        private readonly SocialAccessTokenService $accessTokens,
    ) {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
            'twitter' => new XDriver,
        ];
    }

    public function publish(SocialPost $post): void
    {
        $post->update(['status' => 'publishing']);

        // Scope accounts to the post's own workspace to prevent cross-workspace publishing.
        $accounts = SocialAccount::where('workspace_id', $post->workspace_id)
            ->whereIn('id', $post->target_accounts ?? [])
            ->get();

        $results = (array) $post->publish_results;
        $targetKeys = array_fill_keys(array_map('intval', (array) $post->target_accounts), true);
        foreach (array_diff(array_keys($targetKeys), $accounts->modelKeys()) as $missingId) {
            if (($results[$missingId]['status'] ?? '') !== 'unknown') {
                $results[$missingId] = ['status' => 'failed', 'error' => 'This destination was disconnected. Reconnect it or remove it from the post.'];
            }
        }
        $publishedUrls = collect(array_intersect_key($results, $targetKeys))->pluck('url')->filter()->values()->all();

        foreach ($accounts as $account) {
            $link = SocialPostAccount::firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $account->id],
                ['status' => 'pending']
            );

            if ($account->network === 'twitter') {
                $result = app(XDestinationPublisher::class)->advance($post, $account, $this->payloadFor($post, $account));
                $link->update([
                    'status' => $result['status'] === 'published' ? 'published' : 'pending',
                    'platform_post_id' => $result['post_id'] ?? null,
                    'error' => $result['error'] ?? null,
                    'published_at' => $result['status'] === 'published' ? now() : null,
                ]);
                $results[$account->id] = $result;
                if (! empty($result['url'])) {
                    $publishedUrls[] = $result['url'];
                }

                continue;
            }

            // On job retry, skip accounts already successfully published.
            if (in_array($link->status, ['published', 'processing'], true)) {
                $results[$account->id] = array_merge($results[$account->id] ?? [], [
                    'status' => $link->status,
                    'post_id' => $link->platform_post_id,
                ]);

                continue;
            }

            $driver = $this->drivers[$account->network] ?? null;
            if (! $driver) {
                $link->update(['status' => 'failed', 'error' => "No driver for network {$account->network}."]);
                $results[$account->id] = ['status' => 'failed'];

                continue;
            }

            try {
                $account = $this->accessTokens->fresh($account);
                $platformId = $driver->publish($account, $this->payloadFor($post, $account));
                $requiresProcessingCheck = $driver instanceof ChecksPublishProcessing;
                $linkStatus = $requiresProcessingCheck ? 'processing' : 'published';
                $link->update([
                    'status' => $linkStatus,
                    'platform_post_id' => $platformId,
                    'published_at' => $requiresProcessingCheck ? null : now(),
                    'error' => null,
                ]);
                $results[$account->id] = ['status' => $linkStatus, 'post_id' => $platformId];
                if ($driver instanceof InstagramSocialDriver) {
                    $permalink = $driver->permalink($account, $platformId);
                    if ($permalink) {
                        $results[$account->id]['url'] = $permalink;
                        $publishedUrls[] = $permalink;
                    }
                }
                if ($driver instanceof YoutubeDriver && $driver->warnings() !== []) {
                    $results[$account->id]['warnings'] = $driver->warnings();
                }
                if ($account->network === 'youtube') {
                    $publishedUrls[] = 'https://www.youtube.com/watch?v='.$platformId;
                }
            } catch (\Throwable $e) {
                // Store a sanitized message; full details go to the log.
                Log::error('Social publish failed', [
                    'post_id' => $post->id,
                    'account_id' => $account->id,
                    'network' => $account->network,
                    'error' => $e->getMessage(),
                ]);
                $link->update(['status' => 'failed', 'error' => 'Publish failed. See application logs for details.']);
                $results[$account->id] = ['status' => 'failed'];
            }
        }

        $currentResults = array_intersect_key($results, $targetKeys);
        $succeededCount = collect($currentResults)->filter(fn ($r) => $r['status'] === 'published')->count();
        $failedCount = collect($currentResults)->filter(fn ($r) => in_array($r['status'] ?? '', ['failed', 'unknown'], true))->count();
        $processingCount = collect($currentResults)->filter(fn ($r) => in_array($r['status'] ?? '', ['processing', 'uploading'], true))->count();
        $allFailed = $succeededCount === 0;

        // Keep the post retryable whenever one account failed. Published account
        // links are skipped on the next attempt, while failed links are retried.
        $finalStatus = $processingCount > 0 ? 'publishing' : ($failedCount > 0 || $currentResults === [] ? 'failed' : 'published');

        $post->update([
            'status' => $finalStatus,
            'published_at' => $failedCount === 0 && $processingCount === 0 && ! $allFailed ? now() : null,
            'publish_results' => $results,
            'provider_post_id' => count($currentResults) === 1 && $succeededCount === 1
                ? (string) data_get(collect($currentResults)->first(), 'post_id')
                : null,
            'post_url' => count(array_unique($publishedUrls)) === 1 ? array_values(array_unique($publishedUrls))[0] : null,
        ]);

        if ($post->accountLinks()->where('status', 'processing')->exists()) {
            ConfirmSocialPostProcessingJob::dispatch($post->id)->delay(now()->addMinute())->onQueue('social');
        }

        if (! $allFailed && $failedCount === 0 && $processingCount === 0) {
            UsageMeter::track($post->workspace_id, 'social_posts');
            $this->mediaLifecycle->releaseAfterSuccessfulPublish($post);
        }

        // The queue job must retry failed accounts. Leaving the exception
        // swallowed here makes a temporary provider outage look permanent and
        // prevents Laravel from applying its retry/backoff policy. Published
        // account links are skipped on the next attempt, so this is safe for
        // partial success.
        if ($failedCount > 0) {
            throw new \RuntimeException("{$failedCount} social account publish attempt(s) failed.");
        }
    }

    /**
     * Refresh provider-side processing and return true once no destination is
     * still processing. Provider lookup outages leave the link untouched so
     * the confirmation job can retry without re-uploading the media.
     */
    public function confirmProcessing(SocialPost $post): bool
    {
        $post->load('accountLinks.account');
        $results = (array) $post->publish_results;

        foreach ($post->accountLinks->whereIn('social_account_id', (array) $post->target_accounts)->where('status', 'processing') as $link) {
            $account = $link->account;
            $driver = $account ? ($this->drivers[$account->network] ?? null) : null;
            if (! $account || ! $driver instanceof ChecksPublishProcessing) {
                $link->update(['status' => 'failed', 'error' => 'Provider processing status cannot be checked.']);
                $results[$link->social_account_id] = ['status' => 'failed', 'post_id' => $link->platform_post_id];

                continue;
            }

            try {
                $account = $this->accessTokens->fresh($account);
                $check = $driver->checkPublishProcessing($account, (string) $link->platform_post_id);
            } catch (\Throwable $e) {
                Log::warning('Social processing confirmation failed and will retry.', [
                    'post_id' => $post->id,
                    'account_id' => $account->id,
                    'network' => $account->network,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($check['status'] === 'processing') {
                continue;
            }

            $link->update([
                'status' => $check['status'],
                'published_at' => $check['status'] === 'published' ? now() : null,
                'error' => $check['status'] === 'failed' ? ($check['error'] ?? 'Provider processing failed.') : null,
            ]);
            $results[$account->id] = array_filter([
                'status' => $check['status'],
                'post_id' => $link->platform_post_id,
                'url' => $check['url'] ?? null,
            ]);
        }

        $post->unsetRelation('accountLinks');
        $links = $post->accountLinks()->whereIn('social_account_id', (array) $post->target_accounts)->get();
        $currentResults = array_intersect_key($results, array_fill_keys(array_map('intval', (array) $post->target_accounts), true));
        if ($links->where('status', 'processing')->isNotEmpty()
            || collect($currentResults)->contains(fn ($result) => in_array($result['status'] ?? '', ['uploading', 'processing'], true))) {
            $post->update(['status' => 'publishing', 'publish_results' => $results]);

            return false;
        }

        if ($links->where('status', 'failed')->isNotEmpty()
            || collect($currentResults)->contains(fn ($result) => in_array($result['status'] ?? '', ['unknown', 'failed'], true))) {
            $post->update(['status' => 'failed', 'publish_results' => $results]);

            return true;
        }

        $urls = collect($results)->pluck('url')->filter()->values();
        $post->update([
            'status' => 'published',
            'published_at' => now(),
            'publish_results' => $results,
            'post_url' => $urls->count() === 1 ? $urls->first() : $post->post_url,
        ]);
        UsageMeter::track($post->workspace_id, 'social_posts');
        $this->mediaLifecycle->releaseAfterSuccessfulPublish($post);

        return true;
    }

    /** @return array<string, mixed> */
    private function payloadFor(SocialPost $post, SocialAccount $account): array
    {
        $payload = $post->toArray();
        $override = (array) data_get($post->platform_payloads, $account->network, []);

        if ((bool) ($override['customize'] ?? false)) {
            foreach (['title', 'body', 'media_urls'] as $field) {
                if (array_key_exists($field, $override)) {
                    $payload[$field] = $override[$field];
                }
            }
        }

        if ($account->network === 'twitter') {
            $payload['x_oauth_client_id'] = data_get($account->meta, 'oauth_client_id');
            $urls = (array) ($payload['media_urls'] ?? []);
            $payload['media_ids'] = array_key_exists('media_ids', $override) ? (array) $override['media_ids']
                : Media::whereIn('id', $post->media()->pluck('media.id'))
                    ->get()->filter(fn ($media) => in_array($media->url(), $urls, true))->pluck('id')->all();
        }

        $options = array_merge(
            (array) ($override['options'] ?? []),
            (array) data_get($override, 'account_options.'.$account->id, [])
        );

        if ($account->network === 'youtube') {
            $payload['youtube_options'] = array_merge((array) $post->youtube_options, $options);
        } elseif ($account->network === 'tiktok') {
            $payload['tiktok_options'] = array_merge([
                'privacy_level' => 'SELF_ONLY',
                // Legacy scheduled/API posts did not have an explicit options
                // envelope; their original submit action remains authoritative.
                'consent' => true,
            ], $options);
        } else {
            $payload[$account->network.'_options'] = $options;
        }

        return $payload;
    }

    /** @param array<string, mixed> $postData */
    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void
    {
        $account = $this->accessTokens->fresh($account);
        $driver = $this->drivers[$account->network] ?? null;

        if (! $driver instanceof EditsPublishedPosts) {
            throw new \LogicException(ucfirst($account->network).' does not support editing published posts through its API.');
        }

        $driver->updatePublishedPost($account, $platformPostId, $postData);
    }

    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void
    {
        $account = $this->accessTokens->fresh($account);
        $driver = $this->drivers[$account->network] ?? null;

        if (! $driver instanceof DeletesPublishedPosts) {
            throw new \LogicException(ucfirst($account->network).' does not support deleting published posts through its API.');
        }

        $driver->deletePublishedPost($account, $platformPostId);
    }
}
