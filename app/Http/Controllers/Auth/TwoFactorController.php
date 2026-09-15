<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SecondFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    /**
     * Show 2FA setup page (enables and generates QR).
     */
    public function show(Request $request): Response
    {
        $user = $request->user();

        $qrCode = null;
        $secretKey = null;
        $recoveryCodes = [];

        if (! $user->hasTwoFactorEnabled()) {
            $secretKey = $this->google2fa->generateSecretKey();
            $qrCode = $this->google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secretKey
            );
            $request->session()->put('2fa_secret', $secretKey);
        } else {
            $recoveryCodes = $user->two_factor_recovery_codes ?? [];
        }

        return Inertia::render('Profile/TwoFactor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'qrCode' => $qrCode,
            'secretKey' => $secretKey,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    /**
     * Confirm and enable 2FA using user-provided OTP.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);

        $user = $request->user();
        $secret = $request->session()->get('2fa_secret');

        if (! $secret || ! $this->google2fa->verifyKey($secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'Invalid verification code.']);
        }

        $user->update([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
            'two_factor_confirmed_at' => now(),
        ]);

        $request->session()->forget('2fa_secret');

        return redirect()->route('client.profile.2fa')->with('success', 'Two-factor authentication enabled.');
    }

    /**
     * Disable 2FA for the user.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $request->user()->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return redirect()->route('client.profile.2fa')->with('success', 'Two-factor authentication disabled.');
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateCodes(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $request->user()->update([
            'two_factor_recovery_codes' => $this->generateRecoveryCodes(),
        ]);

        return redirect()->route('client.profile.2fa')->with('success', 'Recovery codes regenerated.');
    }

    /**
     * Verify 2FA challenge after login (session-based, before full auth).
     */
    public function challenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('2fa_user_id') || $request->session()->get('2fa_expires_at', 0) <= now()->timestamp) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Verify OTP or recovery code to complete login.
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        if ($request->session()->get('2fa_expires_at', 0) <= now()->timestamp) {
            $request->session()->forget(['2fa_user_id', '2fa_expires_at', '2fa_remember']);

            return redirect()->route('login')->withErrors(['email' => 'Please sign in again.']);
        }
        $userId = $request->session()->get('2fa_user_id');
        $user = User::find($userId);
        $code = $request->input('code');
        $valid = $user && app(SecondFactorService::class)->verify($user, $code);

        if (! $valid) {
            return back()->withErrors(['code' => 'Invalid code.']);
        }

        auth()->login($user, $request->session()->get('2fa_remember', false));
        $request->session()->forget(['2fa_user_id', '2fa_expires_at', '2fa_remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('client.dashboard'));
    }

    private function generateRecoveryCodes(): array
    {
        return Collection::times(8, fn () => strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)))->all();
    }
}
