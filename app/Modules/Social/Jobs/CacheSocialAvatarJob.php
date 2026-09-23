<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\SocialAvatarStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Copies a newly connected account's picture into storage.
 *
 * Queued so connecting an account never waits on a CDN, and dispatched
 * straight away because the provider's link is only valid for a short time —
 * days for Meta, hours for TikTok.
 */
class CacheSocialAvatarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $accountId) {}

    public function handle(SocialAvatarStore $avatars): void
    {
        $account = SocialAccount::find($this->accountId);
        if ($account) {
            $avatars->store($account);
        }
    }
}
