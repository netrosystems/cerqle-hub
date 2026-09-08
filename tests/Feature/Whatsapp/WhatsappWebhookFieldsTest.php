<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Whatsapp\Services\WhatsappWebhookFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappWebhookFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollout_preserves_existing_fields_and_adds_coexistence_fields(): void
    {
        config(['whatsapp.coexistence_enabled' => true]);
        Http::fake(['*/subscriptions' => Http::response(['data' => [[
            'object' => 'whatsapp_business_account', 'fields' => [['name' => 'security']],
        ]]])]);
        $fields = explode(',', WhatsappWebhookFields::registrationFields('DEMO_APP', 'test-token'));
        foreach (['security', 'messages', 'account_update', 'smb_message_echoes', 'history', 'smb_app_state_sync'] as $field) {
            $this->assertContains($field, $fields);
        }
    }

    public function test_failed_read_does_not_overwrite_subscription_fields(): void
    {
        config(['whatsapp.coexistence_enabled' => true]);
        Http::fake(['*/subscriptions' => Http::response([], 503)]);
        $this->expectException(\RuntimeException::class);
        WhatsappWebhookFields::registrationFields('DEMO_APP', 'test-token');
    }
}
