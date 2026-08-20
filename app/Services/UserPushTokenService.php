<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPushToken;

class UserPushTokenService
{
    public function register(
        User $user,
        ?string $token,
        ?string $deviceName = null,
        string $provider = 'onesignal',
    ): ?UserPushToken {
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            return null;
        }

        return UserPushToken::updateOrCreate(
            [
                'provider' => $provider,
                'token' => $token,
            ],
            [
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * @return list<string>
     */
    public function activeTokensFor(User $user, string $provider = 'onesignal'): array
    {
        return UserPushToken::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->whereNull('revoked_at')
            ->pluck('token')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function revoke(User $user, string $token, string $provider = 'onesignal'): void
    {
        UserPushToken::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('token', trim($token))
            ->update(['revoked_at' => now()]);
    }
}
