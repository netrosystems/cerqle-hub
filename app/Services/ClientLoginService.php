<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ClientLoginService
{
    /** Returns the challenge URL when a second factor is still required. */
    public function login(Request $request, User $user, bool $remember = false): ?string
    {
        if (! $user->canAuthenticate()) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => __('Your account is inactive.')]);
        }

        if ($user->hasTwoFactorEnabled()) {
            Auth::guard('web')->logout();
            $request->session()->regenerate();
            $request->session()->put([
                '2fa_user_id' => $user->id,
                '2fa_expires_at' => now()->addMinutes(10)->timestamp,
                '2fa_remember' => $remember,
            ]);

            return route('auth.two-factor.challenge');
        }

        Auth::guard('web')->login($user, $remember);
        $request->session()->forget(['2fa_user_id', '2fa_expires_at', '2fa_remember']);
        $request->session()->regenerate();

        return null;
    }
}
