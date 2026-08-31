<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Support\Facades\Cache;

class SocialAccessTokenService
{
    private const REFRESHABLE_NETWORKS = ['youtube', 'tiktok', 'linkedin'];

    private const REFRESH_BUFFER_MINUTES = 10;

    public function __construct(private readonly OAuthManager $oauthManager) {}

    /**
     * Return an account with a usable access token, refreshing it shortly
     * before expiry. A per-account lock prevents concurrent workers from
     * rotating the same refresh token at the same time.
     */
    public function fresh(SocialAccount $account): SocialAccount
    {
        if (! $this->needsRefresh($account)) {
            return $account;
        }

        if (blank($account->refresh_token)) {
            throw new \RuntimeException(ucfirst($account->network).' authorization must be reconnected.');
        }

        return Cache::lock('social-access-token:'.$account->id, 30)->block(10, function () use ($account): SocialAccount {
            $current = SocialAccount::findOrFail($account->id);

            // Another worker may have refreshed the token while this request
            // waited for the lock.
            if (! $this->needsRefresh($current)) {
                return $current;
            }

            $refreshed = $this->oauthManager->refresh($current->network, $current->refresh_token);
            $expiresIn = max(60, (int) ($refreshed['expires_in'] ?? 3600));

            $current->update([
                'access_token' => $refreshed['access_token'],
                'refresh_token' => $refreshed['refresh_token'] ?? $current->refresh_token,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'active' => true,
            ]);

            return $current->refresh();
        });
    }

    private function needsRefresh(SocialAccount $account): bool
    {
        if (! in_array($account->network, self::REFRESHABLE_NETWORKS, true)) {
            return false;
        }

        return $account->token_expires_at !== null
            && $account->token_expires_at->lte(now()->addMinutes(self::REFRESH_BUFFER_MINUTES));
    }
}
