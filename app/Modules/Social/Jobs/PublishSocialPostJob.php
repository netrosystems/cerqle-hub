<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use App\Services\ClientAccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Large videos can require several minutes to download and upload through
    // YouTube's resumable endpoint. The worker must exceed this timeout too.
    public int $timeout = 1800;

    public function __construct(public readonly int $postId) {}

    public function handle(SocialPublisher $publisher): void
    {
        $access = app(ClientAccessService::class);
        $post = SocialPost::find($this->postId);

        // Post deleted or already fully published — nothing to do.
        if (! $post || $post->status === 'published') {
            return;
        }

        if (! $access->allowsWorkspaceWrite($post->workspace_id)) {
            $post->update([
                'status' => 'failed',
                'publish_results' => array_merge($post->publish_results ?? [], [
                    'access' => ['ok' => false, 'error' => 'Publishing paused because the subscription is inactive.'],
                ]),
            ]);

            return;
        }

        $publisher->publish($post);
    }
}
