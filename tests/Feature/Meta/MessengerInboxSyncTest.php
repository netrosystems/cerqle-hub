<?php

namespace Tests\Feature\Meta;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessengerInboxSyncTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_recovers_missed_inbound_page_messages_without_importing_outbound_messages_or_duplicates(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Test Page',
            'credentials' => ['page_access_token' => 'page-token'],
            'meta_json' => ['page_id' => '550523921480800'],
            'status' => 'active',
        ]);

        Event::fake([ContactCreated::class, MessageReceived::class]);
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/550523921480800/conversations')) {
                return Http::response([
                    'data' => [['id' => 'thread-1', 'updated_time' => '2026-08-22T11:36:21+0000']],
                ]);
            }

            if (str_contains($url, '/thread-1/messages')) {
                return Http::response([
                    'data' => [
                        [
                            'id' => 'outbound-message',
                            'message' => 'Page reply',
                            'from' => ['id' => '550523921480800', 'name' => 'Test Page'],
                            'created_time' => '2026-08-22T11:37:00+0000',
                        ],
                        [
                            'id' => 'inbound-message',
                            'message' => 'Hello from Facebook',
                            'from' => ['id' => 'page-scoped-user-1', 'name' => 'Customer'],
                            'created_time' => '2026-08-22T11:36:21+0000',
                        ],
                    ],
                ]);
            }

            if (str_contains($url, '/page-scoped-user-1')) {
                return Http::response([
                    'id' => 'page-scoped-user-1',
                    'first_name' => 'Test',
                    'last_name' => 'Customer',
                ]);
            }

            return Http::response(['error' => ['message' => 'Unexpected test URL']], 500);
        });

        $driver = app(MessengerDriver::class);

        $this->assertSame(1, $driver->syncRecentMessages($account));
        $this->assertSame(0, $driver->syncRecentMessages($account->fresh()));

        $this->assertDatabaseHas('messages', [
            'channel' => 'messenger',
            'direction' => 'in',
            'provider_message_id' => 'inbound-message',
            'body' => 'Hello from Facebook',
        ]);
        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'outbound-message']);
        $this->assertSame(1, Message::where('provider_message_id', 'inbound-message')->count());

        $stored = Message::where('provider_message_id', 'inbound-message')
            ->with('conversation.contact')
            ->firstOrFail();

        $this->assertSame($workspace->id, $stored->conversation->workspace_id);
        $this->assertSame($account->id, $stored->conversation->channel_account_id);
        $this->assertSame('page-scoped-user-1', $stored->conversation->external_thread_id);
        $this->assertSame('Test', $stored->conversation->contact->first_name);
        $this->assertNotNull($account->fresh()->meta_json['messenger_last_synced_at'] ?? null);
    }

    #[Test]
    public function webhook_page_id_matching_is_string_safe(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'messenger',
            'provider' => 'meta',
            'display_name' => 'Numeric Page',
            'credentials' => ['page_access_token' => 'page-token'],
            'meta_json' => ['page_id' => 550523921480800],
            'status' => 'active',
        ]);

        Event::fake([ContactCreated::class, MessageReceived::class]);
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'page-scoped-user-2',
                'first_name' => 'Facebook',
                'last_name' => 'User',
            ]),
        ]);

        $processed = app(MessengerDriver::class)->processWebhookPayload([
            'object' => 'page',
            'entry' => [[
                'id' => '550523921480800',
                'messaging' => [[
                    'sender' => ['id' => 'page-scoped-user-2'],
                    'recipient' => ['id' => '550523921480800'],
                    'timestamp' => 1787398581000,
                    'message' => ['mid' => 'webhook-message', 'text' => 'Webhook hello'],
                ]],
            ]],
        ]);

        $this->assertCount(1, $processed);
        $this->assertDatabaseHas('messages', [
            'provider_message_id' => 'webhook-message',
            'body' => 'Webhook hello',
        ]);
    }
}
