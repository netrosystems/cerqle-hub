<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Services\ClientAccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchScheduledPostsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $access = app(ClientAccessService::class);
        // Atomically flip status to 'publishing' before dispatching so a second
        // scheduler tick cannot pick up the same post and dispatch it twice.
        $affected = SocialPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get(['id']);

        foreach ($affected as $post) {
            $workspaceId = SocialPost::whereKey($post->id)->value('workspace_id');
            if (! $workspaceId || ! $access->allowsWorkspaceWrite((int) $workspaceId)) {
                continue;
            }

            // updateOrFail pattern: only dispatch if we are the one who flipped the status.
            $updated = SocialPost::where('id', $post->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'publishing']);

            if ($updated) {
                PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            }
        }
    }
}
