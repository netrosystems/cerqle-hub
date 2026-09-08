<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappWebhookFieldBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_live_fields_cannot_create_inbound_messages_or_contacts(): void
    {
        Http::preventStrayRequests();
        $context = $this->createSubscribedWorkspaceContext();
        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $context['workspace']->id,
            'waba_id' => 'DEMO_WABA',
        ]);
        ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'whatsapp',
            'display_name' => 'Demo',
            'phone_number_id' => 'DEMO_PHONE',
            'business_account_id' => 'DEMO_WABA',
            'status' => 'active',
        ]);
        $driver = app(WhatsappDriver::class);
        foreach (['history', 'smb_app_state_sync', 'smb_message_echoes', 'unknown', ''] as $field) {
            $this->assertSame([], $driver->processWebhookPayload([
                'entry' => [[
                    'id' => 'DEMO_WABA',
                    'changes' => [[
                        'field' => $field,
                        'value' => [
                            'metadata' => ['phone_number_id' => 'DEMO_PHONE'],
                            'messages' => [[
                                'from' => '15550000001',
                                'id' => 'DEMO_MESSAGE',
                                'timestamp' => (string) now()->timestamp,
                                'type' => 'text',
                                'text' => ['body' => 'start'],
                            ]],
                        ],
                    ]],
                ]],
            ]));
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('contacts', 0);
        Http::assertNothingSent();
    }
}
