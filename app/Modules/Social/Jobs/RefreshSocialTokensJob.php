<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\SocialAccessTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshSocialTokensJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SocialAccessTokenService $accessTokens): void
    {
        // Networks that support programmatic refresh
        $refreshable = ['youtube', 'tiktok', 'linkedin'];

        SocialAccount::where('active', true)
            ->whereIn('network', $refreshable)
            ->where('token_expires_at', '<=', now()->addMinutes(10))
            ->whereNotNull('refresh_token')
            ->chunkById(100, function ($accounts) use ($accessTokens) {
                foreach ($accounts as $account) {
                    try {
                        $accessTokens->fresh($account);

                        Log::info('Social token refreshed', ['network' => $account->network, 'account_id' => $account->id]);
                    } catch (\Throwable $e) {
                        Log::warning('Social token refresh failed', [
                            'network' => $account->network,
                            'account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
