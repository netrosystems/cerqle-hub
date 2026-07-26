<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappChatbotSandboxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_inbound_whatsapp_message_receives_a_chatbot_reply_through_the_cloud_api(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $waba = WhatsappBusinessAccount::create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'WABA_SANDBOX',
            'credentials' => ['access_token' => 'sandbox-access-token'],
            'webhook_verify_token' => 'sandbox-verify-token',
            'status' => 'active',
        ]);

        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PHONE_SANDBOX',
            'display_phone' => '+15550001111',
            'verified_name' => 'Cerqle WhatsApp Sandbox',
        ]);

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Cerqle WhatsApp Sandbox Bot',
            'system_prompt' => 'Answer customer questions clearly.',
            'fallback_reply' => 'A team member will follow up shortly.',
            'channels' => ['whatsapp'],
            'enabled' => true,
        ]);

        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'Cerqle WhatsApp Sandbox',
            'phone_number_id' => 'PHONE_SANDBOX',
            'business_account_id' => $waba->waba_id,
            'status' => 'active',
            'meta_json' => ['ai_chatbot_id' => $chatbot->id],
        ]);

        $this->mock(ChatbotRunner::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn('Hello from the Cerqle WhatsApp chatbot sandbox!');
        });

        Http::fake([
            'graph.facebook.com/v25.0/PHONE_SANDBOX/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '+15551234567', 'wa_id' => '15551234567']],
                'messages' => [['id' => 'wamid.SANDBOX_OUTBOUND']],
            ]),
        ]);

        $response = $this->postJson('/webhooks/whatsapp/sandbox-verify-token', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $waba->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '+15550001111',
                            'phone_number_id' => 'PHONE_SANDBOX',
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Sandbox Customer'],
                            'wa_id' => '15551234567',
                        ]],
                        'messages' => [[
                            'from' => '15551234567',
                            'id' => 'wamid.SANDBOX_INBOUND',
                            'timestamp' => now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => 'What are your opening hours?'],
                        ]],
                    ],
                ]],
            ]],
        ]);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $conversation = Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->where('external_thread_id', '15551234567')
            ->firstOrFail();

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15551234567',
            'source' => 'whatsapp_inbound',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'body' => 'What are your opening hours?',
            'provider_message_id' => 'wamid.SANDBOX_INBOUND',
            'status' => 'delivered',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'body' => 'Hello from the Cerqle WhatsApp chatbot sandbox!',
            'provider_message_id' => 'wamid.SANDBOX_OUTBOUND',
            'sent_by' => 'bot',
            'status' => 'sent',
        ]);

        $this->assertSame(2, Message::where('conversation_id', $conversation->id)->count());

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://graph.facebook.com/v25.0/PHONE_SANDBOX/messages'
                && $request->hasHeader('Authorization', 'Bearer sandbox-access-token')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '+15551234567'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello from the Cerqle WhatsApp chatbot sandbox!';
        });
    }
}
