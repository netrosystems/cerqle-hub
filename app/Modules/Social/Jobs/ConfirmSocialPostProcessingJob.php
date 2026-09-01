<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConfirmSocialPostProcessingJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 180;

    public int $timeout = 60;

    public int $uniqueFor = 21600;

    public function __construct(public readonly int $postId) {}

    public function uniqueId(): string
    {
        return (string) $this->postId;
    }

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::find($this->postId);
        if (! $post || $post->status === 'published') {
            return;
        }

        if (! $publisher->confirmProcessing($post)) {
            $this->release(120);
        }
    }

    public function failed(Throwable $exception): void
    {
        $post = SocialPost::find($this->postId);
        if (! $post) {
            return;
        }

        $post->accountLinks()->where('status', 'processing')->update([
            'status' => 'failed',
            'error' => 'Provider processing did not complete within the confirmation window.',
        ]);
        $post->update(['status' => 'failed']);
    }
}
