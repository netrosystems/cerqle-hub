<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Broadcasting\Models\SmsProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmsProviderConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_gateway_screen_only_offers_approved_sms_providers(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.sms-gateways.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Broadcasting/SmsProviders/Index')
                ->has('providers', 3)
                ->where('providers.0.provider', 'twilio')
                ->where('providers.1.provider', 'alaris')
                ->where('providers.2.provider', 'amazon_sns')
            );
    }

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

    public function test_alaris_uses_one_canonical_sender_id(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.sms-gateways.update', 'alaris'), [
                'default' => true,
                'sender_id' => 'KHALIFEH',
                'credentials' => [
                    'base_url' => 'https://sms.example.test:8002/api?',
                    'username' => 'alaris-user',
                    'password' => 'alaris-password',
                ],
            ])
            ->assertSessionHasNoErrors();

        $config = SmsProviderConfig::where('workspace_id', $workspace->id)
            ->where('provider', 'alaris')
            ->firstOrFail();

        $this->assertSame('KHALIFEH', $config->sender_id);
        $this->assertSame('KHALIFEH', $config->credentials['sender_id']);
    }

    public function test_alaris_connection_can_be_tested_without_sending_sms(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'alaris',
            'credentials' => [
                'base_url' => 'https://sms.example.test:8002/api?',
                'username' => 'alaris-user',
                'password' => 'alaris-password',
                'sender_id' => 'KHALIFEH',
            ],
            'sender_id' => 'KHALIFEH',
            'default' => true,
        ]);

        Http::fake([
            'https://sms.example.test:8002/api*' => Http::response([
                ['status' => 'UNKNOWN'],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('client.sms-gateways.test', 'alaris'))
            ->assertSessionHas('success', 'PROSMS authentication and API connectivity are working.');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'command=query'));
    }
}
