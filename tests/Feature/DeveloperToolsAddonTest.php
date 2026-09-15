<?php

namespace Tests\Feature;

use App\Contracts\AddonBillingGatewayInterface;
use App\Jobs\DispatchWebhookJob;
use App\Models\Client;
use App\Models\ClientAddonSubscription;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\AddonEntitlementService;
use App\Services\Billing\PaddleGateway;
use App\Services\Billing\PayPalGateway;
use App\Services\Billing\StripeGateway;
use App\Services\WebhookDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeveloperToolsAddonTest extends TestCase
{
    use RefreshDatabase;

    public function test_developer_tools_are_hidden_and_denied_by_default_while_media_stays_available(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->get(route('client.api-tokens.index'))
            ->assertRedirect(route('client.addons.index'));

        $this->actingAs($user)
            ->get(route('client.media.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('client.addons.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Addons/Index')
                ->where('addon.key', 'developer_tools')
                ->where('addon.price_cents', 5000)
                ->where('subscription', null)
            );
    }

    public function test_active_addon_grants_web_and_external_api_access_without_affecting_mobile_auth_api(): void
    {
        $user = $this->clientUser();
        $token = $user->createToken('mobile-device', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/tokens')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        ClientAddonSubscription::create([
            'client_id' => $user->client_id,
            'addon_key' => AddonEntitlementService::DEVELOPER_TOOLS,
            'purchased_by_user_id' => $user->id,
            'status' => ClientAddonSubscription::STATUS_ACTIVE,
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_devtools_test',
            'starts_at' => now(),
        ]);

        $this->actingAs($user)->get(route('client.api-tokens.index'))->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/tokens')->assertOk();
    }

    public function test_outbound_webhook_delivery_pauses_without_active_addon(): void
    {
        Bus::fake();
        $user = $this->clientUser();
        $endpoint = WebhookEndpoint::create([
            'user_id' => $user->id,
            'url' => 'https://example.com/webhook',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['contact.created'],
            'enabled' => true,
        ]);
        $service = app(WebhookDispatchService::class);

        $service->dispatch($user, 'contact.created', ['id' => 1]);
        Bus::assertNotDispatched(DispatchWebhookJob::class);

        ClientAddonSubscription::create([
            'client_id' => $user->client_id,
            'addon_key' => AddonEntitlementService::DEVELOPER_TOOLS,
            'purchased_by_user_id' => $user->id,
            'status' => ClientAddonSubscription::STATUS_ACTIVE,
            'gateway' => 'stripe',
            'gateway_subscription_id' => 'sub_webhooks_test',
            'starts_at' => now(),
        ]);

        $service->dispatchToEndpoint($endpoint, 'contact.created', ['id' => 1]);
        Bus::assertDispatched(DispatchWebhookJob::class);
    }

    public function test_all_supported_gateways_implement_addon_billing_contract(): void
    {
        $this->assertTrue(is_subclass_of(StripeGateway::class, AddonBillingGatewayInterface::class));
        $this->assertTrue(is_subclass_of(PayPalGateway::class, AddonBillingGatewayInterface::class));
        $this->assertTrue(is_subclass_of(PaddleGateway::class, AddonBillingGatewayInterface::class));
    }

    private function clientUser(): User
    {
        $client = Client::create([
            'name' => 'Tester Client',
            'email' => 'tester-client@example.com',
            'status' => Client::STATUS_ACTIVE,
        ]);

        return User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'email_verified_at' => now(),
        ]);
    }
}
