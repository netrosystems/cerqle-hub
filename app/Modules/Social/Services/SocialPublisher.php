<?php

namespace App\Modules\Social\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\DeletesPublishedPosts;
use App\Modules\Social\Services\Drivers\EditsPublishedPosts;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\SocialNetworkInterface;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use Illuminate\Support\Facades\Log;

class SocialPublisher
{
    /** @var array<string, SocialNetworkInterface> */
    private array $drivers;

    public function __construct()
    {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
        ];
    }

    public function publish(SocialPost $post): void
    {
        $post->update(['status' => 'publishing']);

        // Scope accounts to the post's own workspace to prevent cross-workspace publishing.
        $accounts = SocialAccount::where('workspace_id', $post->workspace_id)
            ->whereIn('id', $post->target_accounts ?? [])
            ->get();

        $results = [];
        $publishedUrls = [];

        foreach ($accounts as $account) {
            $link = SocialPostAccount::firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $account->id],
                ['status' => 'pending']
            );

            // On job retry, skip accounts already successfully published.
            if ($link->status === 'published') {
                $results[$account->id] = ['status' => 'published', 'post_id' => $link->platform_post_id];

                continue;
            }

            $driver = $this->drivers[$account->network] ?? null;
            if (! $driver) {
                $link->update(['status' => 'failed', 'error' => "No driver for network {$account->network}."]);
                $results[$account->id] = ['status' => 'failed'];

                continue;
            }

            try {
                $platformId = $driver->publish($account, $post->toArray());
                $link->update(['status' => 'published', 'platform_post_id' => $platformId, 'published_at' => now()]);
                $results[$account->id] = ['status' => 'published', 'post_id' => $platformId];
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

        $succeededCount = collect($results)->filter(fn ($r) => $r['status'] === 'published')->count();
        $failedCount = collect($results)->filter(fn ($r) => $r['status'] === 'failed')->count();
        $allFailed = $succeededCount === 0;

        // Keep the post retryable whenever one account failed. Published account
        // links are skipped on the next attempt, while failed links are retried.
        $finalStatus = $failedCount > 0 ? 'failed' : 'published';

        $post->update([
            'status' => $finalStatus,
            'published_at' => $failedCount === 0 && ! $allFailed ? now() : null,
            'publish_results' => $results,
            'provider_post_id' => count($results) === 1 && $succeededCount === 1
                ? (string) data_get(collect($results)->first(), 'post_id')
                : null,
            'post_url' => count($publishedUrls) === 1 ? $publishedUrls[0] : null,
        ]);

        if (! $allFailed && $failedCount === 0) {
            UsageMeter::track($post->workspace_id, 'social_posts');
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

    public function updatePublishedPost(SocialAccount $account, string $platformPostId, array $postData): void
    {
        $driver = $this->drivers[$account->network] ?? null;

        if (! $driver instanceof EditsPublishedPosts) {
            throw new \LogicException(ucfirst($account->network).' does not support editing published posts through its API.');
        }

        $driver->updatePublishedPost($account, $platformPostId, $postData);
    }

    public function deletePublishedPost(SocialAccount $account, string $platformPostId): void
    {
        $driver = $this->drivers[$account->network] ?? null;

        if (! $driver instanceof DeletesPublishedPosts) {
            throw new \LogicException(ucfirst($account->network).' does not support deleting published posts through its API.');
        }

        $driver->deletePublishedPost($account, $platformPostId);
    }
}
