<?php

namespace Tests\Feature;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_is_configured_only_when_every_required_credential_exists(): void
    {
        $incomplete = new IntegrationConfig([
            'provider' => 'oauth_linkedin',
            'credentials' => ['client_id' => 'client-id'],
        ]);
        $complete = new IntegrationConfig([
            'provider' => 'oauth_linkedin',
            'credentials' => ['client_id' => 'client-id', 'client_secret' => 'client-secret'],
        ]);

        $this->assertFalse($incomplete->isConfigured());
        $this->assertTrue($complete->isConfigured());
        $this->assertTrue((new IntegrationConfig(['provider' => 'storage_local']))->isConfigured());
    }

    public function test_google_oauth_flows_are_three_explicit_providers(): void
    {
        $this->assertContains('oauth_google_signin', IntegrationConfig::PROVIDERS);
        $this->assertContains('oauth_youtube', IntegrationConfig::PROVIDERS);
        $this->assertContains('oauth_google_mail', IntegrationConfig::PROVIDERS);

        $this->assertSame('Google Sign-In', IntegrationConfig::LABELS['oauth_google_signin']);
        $this->assertSame('YouTube OAuth', IntegrationConfig::LABELS['oauth_youtube']);
        $this->assertSame('Google Gmail OAuth', IntegrationConfig::LABELS['oauth_google_mail']);
    }

    public function test_incomplete_credentials_cannot_be_enabled(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->putJson(route('admin.integrations.update', 'oauth_linkedin'), [
                'enabled' => true,
                'mode' => 'live',
                'credentials' => ['client_id' => 'client-id'],
            ])
            ->assertJsonValidationErrors('credentials.client_secret');

        $this->assertDatabaseMissing('integration_configs', [
            'provider' => 'oauth_linkedin',
            'mode' => 'live',
        ]);
    }

    public function test_test_connection_uses_the_requested_mode(): void
    {
        $admin = $this->createSuperAdmin();

        $live = IntegrationConfig::create([
            'provider' => 'oauth_linkedin',
            'label' => 'LinkedIn OAuth',
            'mode' => 'live',
            'enabled' => false,
            'credentials' => [],
        ]);
        $test = IntegrationConfig::create([
            'provider' => 'oauth_linkedin',
            'label' => 'LinkedIn OAuth',
            'mode' => 'test',
            'enabled' => true,
            'credentials' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.integrations.test', 'oauth_linkedin'), ['mode' => 'test'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertSame('untested', $live->fresh()->last_test_status);
        $this->assertSame('fail', $test->fresh()->last_test_status);
    }

    public function test_youtube_oauth_presence_check_is_not_recorded_as_a_failure(): void
    {
        Http::fake([
            'https://accounts.google.com/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            ]),
        ]);

        $config = IntegrationConfig::create([
            'provider' => 'oauth_youtube',
            'label' => 'YouTube OAuth',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'client_id' => 'youtube-client-id',
                'client_secret' => 'youtube-client-secret',
            ],
        ]);

        $result = app(ConnectionTester::class)->test($config);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $config->fresh()->last_test_status);
        $this->assertStringContainsString('Complete a YouTube account connection', $result['message']);
    }
}
