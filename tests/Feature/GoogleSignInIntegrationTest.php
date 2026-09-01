<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
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
}
