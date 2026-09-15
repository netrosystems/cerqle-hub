<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

class SecondFactorService
{
    public function verify(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $fresh = User::lockForUpdate()->find($user->id);
            if (! $fresh || ! $fresh->canAuthenticate() || ! $fresh->hasTwoFactorEnabled()) {
                return false;
            }
            if (strlen($code) === 6 && ctype_digit($code)) {
                return app(Google2FA::class)->verifyKey($fresh->two_factor_secret, $code);
            }
            $codes = $fresh->two_factor_recovery_codes ?? [];
            $index = array_search($code, $codes, true);
            if ($index === false) {
                return false;
            }
            unset($codes[$index]);
            $fresh->update(['two_factor_recovery_codes' => array_values($codes)]);

            return true;
        });
    }
}
