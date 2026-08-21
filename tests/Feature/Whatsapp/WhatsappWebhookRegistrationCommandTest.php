<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappWebhookRegistrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_repairs_and_verifies_inbound_webhook_registration(): void
    {
        $this->seedMetaAndWaba();

        Http::fake([
            'graph.facebook.com/v25.0/APP_ID/subscriptions*' => Http::sequence()
                ->push(['success' => true])
                ->push(['data' => [[
                    'object' => 'whatsapp_business_account',
                    'active' => true,
                    'callback_url' => route('webhooks.whatsapp.global.receive'),
                    'fields' => [['name' => 'messages']],
                ]]]),
            'graph.facebook.com/v25.0/WABA_123/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $exitCode = Artisan::call('whatsapp:register-webhook');
        $this->assertSame(0, $exitCode, Artisan::output());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'WABA_123/subscribed_apps'));
    }

    public function test_it_fails_when_messages_field_is_not_subscribed(): void
    {
        $this->seedMetaAndWaba();

        Http::fake([
            'graph.facebook.com/v25.0/APP_ID/subscriptions*' => Http::sequence()
                ->push(['success' => true])
                ->push(['data' => [[
                    'object' => 'whatsapp_business_account',
                    'active' => true,
                    'callback_url' => route('webhooks.whatsapp.global.receive'),
                    'fields' => [['name' => 'account_update']],
                ]]]),
            'graph.facebook.com/v25.0/WABA_123/subscribed_apps*' => Http::response(['success' => true]),
        ]);

        $this->artisan('whatsapp:register-webhook')->assertFailed();
    }

    private function seedMetaAndWaba(): void
    {
        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => 'APP_ID',
                'app_secret' => 'APP_SECRET',
            ],
        ]);

        WhatsappBusinessAccount::factory()->create([
            'waba_id' => 'WABA_123',
            'status' => 'active',
            'credentials' => [],
        ]);
    }
}
