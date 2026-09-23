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
 * Asks each provider for a current picture and stores it.
 *
 * Repairs accounts whose stored link had already expired before copies were
 * kept, and picks up a Page that has changed its logo since connecting. A
 * failure for one account never stops the rest.
 */
class RefreshSocialAvatarsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly ?int $accountId = null) {}

    /** @return array{refreshed:int, unchanged_or_failed:int} */
    public function handle(SocialAvatarStore $avatars): array
    {
        $refreshed = 0;
        $missed = 0;

        SocialAccount::query()
            ->where('active', true)
            ->when($this->accountId, fn ($query) => $query->whereKey($this->accountId))
            ->orderBy('id')
            ->each(function (SocialAccount $account) use ($avatars, &$refreshed, &$missed): void {
                $fresh = $avatars->freshPictureUrl($account);
                // No fresh link (token revoked, provider down): still try the
                // stored one, which is fine for the networks whose links last.
                $avatars->store($account, $fresh) ? $refreshed++ : $missed++;
            });

        return ['refreshed' => $refreshed, 'unchanged_or_failed' => $missed];
    }
}
