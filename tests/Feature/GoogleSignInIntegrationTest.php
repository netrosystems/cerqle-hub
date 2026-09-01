<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleSignInIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_super_admin_credentials_expose_google_login(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);

        $this->googleSignInIntegration(enabled: true);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('socialProviders', fn ($providers) => $providers->contains('google'))
            );
    }

    public function test_enabled_google_credentials_expose_terms_gated_signup(): void
    {
        config()->set('services.google.client_id', null);
        config()->set('services.google.client_secret', null);
        $this->googleSignInIntegration(enabled: true);

        $this->get(route('register'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Register')
                ->where('googleSignupEnabled', true)
            );

        $this->post(route('auth.google.signup'), ['agree_terms' => false])
            ->assertSessionHasErrors('agree_terms');

        $this->post(route('auth.google.signup'), [
            'agree_terms' => true,
            'timezone' => 'Asia/Dhaka',
        ])->assertRedirect();
        $this->assertSame('signup', session('social_auth_context.intent'));

        $this->post(route('auth.google.signup'), [
            'agree_terms' => true,
            'timezone' => 'Asia/Dhaka',
        ], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location');
    }

    public function test_google_redirect_uses_super_admin_client_and_exact_callback(): void
    {
        config()->set('services.google.client_id', 'legacy-environment-client');
        config()->set('services.google.client_secret', 'legacy-environment-secret');

        $this->googleSignInIntegration(enabled: true);

        $response = $this->get(route('auth.social.redirect', 'google'));

        $response->assertRedirect();
        $query = [];
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('admin-google-client', $query['client_id'] ?? null);
        $this->assertSame(route('auth.social.callback', 'google'), $query['redirect_uri'] ?? null);
        $this->assertSame('openid profile email', $query['scope'] ?? null);
    }

    public function test_saved_but_disabled_google_sign_in_is_hidden_and_blocked(): void
    {
        config()->set('services.google.client_id', 'legacy-environment-client');
        config()->set('services.google.client_secret', 'legacy-environment-secret');

        $this->googleSignInIntegration(enabled: false);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('socialProviders', fn ($providers) => ! $providers->contains('google'))
            );

        $this->get(route('auth.social.redirect', 'google'))->assertStatus(503);
    }

    public function test_social_login_credentials_support_long_encrypted_values(): void
    {
        $user = User::factory()->create();
        $accessToken = 'access-'.str_repeat('a', 1800);
        $refreshToken = 'refresh-'.str_repeat('r', 1800);

        $account = $user->socialAccounts()->create([
            'provider' => 'google',
            'provider_id' => 'google-user-123',
            'email' => $user->email,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
        ]);

        $stored = DB::table('social_accounts')->find($account->id);

        $this->assertSame('text', Schema::getColumnType('social_accounts', 'access_token'));
        $this->assertNotSame($accessToken, $stored->access_token);
        $this->assertNotSame($refreshToken, $stored->refresh_token);
        $this->assertSame($accessToken, $account->fresh()->access_token);
        $this->assertSame($refreshToken, $account->fresh()->refresh_token);
    }

    public function test_google_login_returns_a_visible_oauth_error_when_no_account_matches(): void
    {
        $this->googleSignInIntegration(enabled: true);
        $this->mockGoogleCallbackUser('new-google-user', 'new-user@example.test');

        $this->withSession([
            'social_auth_context' => [
                'intent' => 'login',
                'provider' => 'google',
            ],
        ])->get(route('auth.social.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('oauth_provider', 'google')
            ->assertSessionHasErrors('oauth');

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('oauthProvider', 'google')
                ->where('errors.oauth', 'No Cerqle account matches this Google account. Create an account first, or try a different account.')
            );

        $this->assertGuest();
    }

    public function test_google_login_links_a_verified_matching_email_and_signs_in(): void
    {
        $this->googleSignInIntegration(enabled: true);
        $user = User::factory()->create(['email' => 'existing-user@example.test']);
        $this->mockGoogleCallbackUser('existing-google-user', $user->email);

        $this->withSession([
            'social_auth_context' => [
                'intent' => 'login',
                'provider' => 'google',
            ],
        ])->get(route('auth.social.callback', 'google'))
            ->assertRedirect(route('client.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => 'existing-google-user',
        ]);
    }

    private function googleSignInIntegration(bool $enabled): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'oauth_google_signin',
            'label' => IntegrationConfig::LABELS['oauth_google_signin'],
            'mode' => 'live',
            'enabled' => $enabled,
            'credentials' => [
                'client_id' => 'admin-google-client',
                'client_secret' => 'admin-google-secret',
            ],
        ]);
    }

    private function mockGoogleCallbackUser(string $providerId, string $email): void
    {
        $socialUser = (new SocialiteUser)
            ->map([
                'id' => $providerId,
                'name' => 'Google User',
                'email' => $email,
                'avatar' => 'https://example.test/avatar.png',
            ])
            ->setRaw(['email_verified' => true])
            ->setToken('google-access-token')
            ->setRefreshToken('google-refresh-token');

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($socialUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);
    }
}
