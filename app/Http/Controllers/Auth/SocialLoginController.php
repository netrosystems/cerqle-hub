<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\FreePlanActivationService;
use App\Services\GoogleSignInConfigurator;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class SocialLoginController extends Controller
{
    private const ALLOWED_PROVIDERS = ['google', 'github', 'microsoft'];

    private const CONTEXT_KEY = 'social_auth_context';

    public function redirect(Request $request, string $provider, GoogleSignInConfigurator $googleSignIn): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);
        abort_if($provider === 'google' && ! $googleSignIn->apply(), 503, 'Google Sign-In is not configured.');

        $request->session()->put(self::CONTEXT_KEY, [
            'intent' => 'login',
            'provider' => $provider,
            'created_at' => now()->timestamp,
        ]);

        return Socialite::driver($provider)->redirect();
    }

    public function signup(Request $request, GoogleSignInConfigurator $googleSignIn): Response
    {
        abort_if(! $googleSignIn->apply(), 503, 'Google Sign-In is not configured.');

        $validated = $request->validate([
            'agree_terms' => ['accepted'],
            'plan_id' => ['nullable', 'integer', Rule::exists('plans', 'id')],
            'cycle' => ['nullable', Rule::in(['month', 'year'])],
            'timezone' => ['nullable', 'timezone'],
        ], [
            'agree_terms.accepted' => __('You must accept the Terms & Conditions to create an account.'),
        ]);

        $request->session()->put(self::CONTEXT_KEY, [
            'intent' => 'signup',
            'provider' => 'google',
            'plan_id' => $validated['plan_id'] ?? null,
            'cycle' => $validated['cycle'] ?? 'month',
            'timezone' => $validated['timezone'] ?? 'Asia/Dhaka',
            'created_at' => now()->timestamp,
        ]);

        $redirect = Socialite::driver('google')->redirect();

        return $request->header('X-Inertia')
            ? Inertia::location($redirect->getTargetUrl())
            : $redirect;
    }

    public function callback(
        Request $request,
        string $provider,
        GoogleSignInConfigurator $googleSignIn,
        FreePlanActivationService $freePlans,
    ): RedirectResponse {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);
        abort_if($provider === 'google' && ! $googleSignIn->apply(), 503, 'Google Sign-In is not configured.');

        $context = $request->session()->pull(self::CONTEXT_KEY, [
            'intent' => 'login',
            'provider' => $provider,
        ]);
        $intent = ($context['provider'] ?? null) === $provider ? ($context['intent'] ?? 'login') : 'login';

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            return redirect()->route($intent === 'signup' ? 'register' : 'login')
                ->withErrors(['email' => __('Social login failed. Please try again.')]);
        }

        $email = $socialUser->getEmail();
        if (! $email) {
            return redirect()->route($intent === 'signup' ? 'register' : 'login')
                ->withErrors(['email' => __('No email address returned by Google.')]);
        }

        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->with('user')
            ->first();

        if ($existing?->user) {
            if ($intent === 'signup') {
                return redirect()->route('register')
                    ->withErrors(['email' => __('An account already exists. Sign in instead.')]);
            }

            $existing->update([
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ]);
            $this->markProviderVerifiedEmail($existing->user, $provider, $socialUser);
            Auth::login($existing->user, true);

            return redirect()->intended(route('client.dashboard'));
        }

        $user = User::where('email', $email)->first();
        if ($user && $intent === 'signup') {
            return redirect()->route('register')
                ->withErrors(['email' => __('An account already exists. Sign in instead.')]);
        }

        if ($user && ! $this->providerEmailIsVerified($provider, $socialUser)) {
            return redirect()->route('login')
                ->withErrors(['email' => __('The provider could not confirm ownership of this email address.')]);
        }

        if (! $user && $intent !== 'signup') {
            return redirect()->route('login')
                ->withErrors(['email' => __('No account found. Create an account first.')]);
        }

        if (! $user) {
            if (! config('auth.allow_registration', true)) {
                return redirect()->route('register')
                    ->withErrors(['email' => __('New account registration is disabled.')]);
            }

            $verified = $this->providerEmailIsVerified($provider, $socialUser);
            $user = DB::transaction(function () use ($socialUser, $email, $context, $verified): User {
                $name = $socialUser->getName() ?? $email;
                $client = Client::create([
                    'name' => $name,
                    'email' => $email,
                    'status' => Client::STATUS_ACTIVE,
                    'base_currency' => 'USD',
                    'currency_symbol' => '$',
                    'currency_position' => 'before',
                ]);

                return User::create([
                    'name' => $name,
                    'email' => $email,
                    'password' => bcrypt(Str::random(32)),
                    'role' => User::ROLE_CLIENT,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => $verified ? now() : null,
                    'client_id' => $client->id,
                    'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
                    'timezone' => $context['timezone'] ?? 'Asia/Dhaka',
                ]);
            });

            event(new Registered($user));
        } else {
            $this->markProviderVerifiedEmail($user, $provider, $socialUser);
        }

        $user->socialAccounts()->updateOrCreate(
            ['provider' => $provider, 'provider_id' => $socialUser->getId()],
            [
                'email' => $email,
                'avatar_url' => $socialUser->getAvatar(),
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
            ],
        );

        Auth::login($user, true);

        $planId = $context['plan_id'] ?? null;
        if ($intent === 'signup' && $planId) {
            $plan = Plan::where('enabled', true)->find($planId);
            if ($plan?->isFree()) {
                $freePlans->activate($user, $plan, $context['cycle'] ?? 'month');
            } elseif ($plan) {
                return redirect()->route('client.pricing')->with([
                    'plan_id' => $plan->id,
                    'cycle' => $context['cycle'] ?? 'month',
                    'success' => __('Account created. Select a payment method to complete your subscription.'),
                ]);
            }
        }

        return redirect()->intended(route('client.dashboard'));
    }

    private function markProviderVerifiedEmail(User $user, string $provider, mixed $socialUser): void
    {
        if (! $user->hasVerifiedEmail() && $this->providerEmailIsVerified($provider, $socialUser)) {
            $user->markEmailAsVerified();
        }
    }

    private function providerEmailIsVerified(string $provider, mixed $socialUser): bool
    {
        if ($provider !== 'google') {
            return false;
        }

        $raw = is_array($socialUser->user ?? null) ? $socialUser->user : [];

        return filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
