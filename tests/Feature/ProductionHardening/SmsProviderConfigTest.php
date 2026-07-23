<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Broadcasting\Models\SmsProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_sms_credentials_are_rejected_before_persistence(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'twilio'), [
                'default' => true,
                'credentials' => ['account_sid' => 'AC_test'],
            ])
            ->assertSessionHasErrors('credentials.auth_token');

        $this->assertDatabaseMissing('sms_provider_configs', [
            'workspace_id' => $workspace->id,
            'provider' => 'twilio',
        ]);
    }

    public function test_masked_values_can_update_an_existing_complete_provider(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'AC_test', 'auth_token' => 'secret'],
            'default' => true,
        ]);

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'twilio'), [
                'default' => true,
                'credentials' => [
                    'account_sid' => '••••••••••••',
                    'auth_token' => '••••••••••••',
                ],
            ])
            ->assertSessionHasNoErrors();

        $config = SmsProviderConfig::where('workspace_id', $workspace->id)
            ->where('provider', 'twilio')
            ->firstOrFail();

        $this->assertSame('AC_test', $config->credentials['account_sid']);
        $this->assertSame('secret', $config->credentials['auth_token']);
    }

    public function test_alaris_requires_an_https_api_endpoint(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'alaris'), [
                'default' => true,
                'credentials' => [
                    'base_url' => 'http://sms.example.test:8002/api',
                    'username' => 'alaris-user',
                    'password' => 'alaris-password',
                    'sender_id' => 'CERQLE',
                ],
            ])
            ->assertSessionHasErrors('credentials.base_url');

        $this->assertDatabaseMissing('sms_provider_configs', [
            'workspace_id' => $workspace->id,
            'provider' => 'alaris',
        ]);
    }
}
