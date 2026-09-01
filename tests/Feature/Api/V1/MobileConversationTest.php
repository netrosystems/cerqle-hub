<?php

namespace Tests\Feature\Api\V1;

use App\Events\MessageStatusUpdated;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_conversations_index_includes_assigned_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Alice',
        ]);

        $assignedAgent = User::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'John Agent',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'assigned_user_id' => $assignedAgent->id,
            'assigned_to' => 'human',
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/mobile/conversations');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.assigned_user_id', $assignedAgent->id)
            ->assertJsonPath('data.0.assigned_to', 'human')
            ->assertJsonPath('data.0.assigned_user.id', $assignedAgent->id)
            ->assertJsonPath('data.0.assigned_user.name', 'John Agent');
    }

    public function test_mobile_conversation_show_returns_all_messages(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Bob',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        for ($i = 1; $i <= 55; $i++) {
            $conversation->messages()->create([
                'direction' => 'in',
                'channel' => 'webchat',
                'type' => 'text',
                'body' => "Message {$i}",
                'status' => 'delivered',
                'sent_by' => 'human',
                'sent_at' => now()->addSeconds($i),
            ]);
        }

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/v1/mobile/conversations/{$conversation->uuid}");

        $response->assertOk();
        $this->assertCount(55, $response->json('messages'));
        $this->assertEquals('Message 55', $response->json('messages.54.body'));
    }

    public function test_mobile_conversations_index_supports_live_folder_and_returns_online_status(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website Widget',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Live',
            'last_name' => 'Visitor',
        ]);

        $liveConv = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
            'webchat_last_seen_at' => now(),
        ]);

        $offlineConv = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
            'webchat_last_seen_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($user);

        // Standard index
        $res = $this->getJson('/api/v1/mobile/conversations');
        $res->assertOk()
            ->assertJsonPath('meta.live_users_count', 1);

        // Filter folder=live
        $liveRes = $this->getJson('/api/v1/mobile/conversations?folder=live');
        $liveRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $liveConv->id)
            ->assertJsonPath('data.0.is_online', true);
    }

    public function test_mobile_inbox_setup_and_counts_include_live_users_count(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website Widget',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Online',
            'last_name' => 'User',
        ]);

        Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
            'webchat_last_seen_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $setupRes = $this->getJson('/api/v1/mobile/inbox/setup');
        $setupRes->assertOk()
            ->assertJsonPath('live_users_count', 1);

        $countsRes = $this->getJson('/api/v1/mobile/inbox/counts');
        $countsRes->assertOk()
            ->assertJsonPath('live', 1)
            ->assertJsonPath('all', 1);
    }

    public function test_mobile_can_trigger_open_widget_for_live_visitor(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website Widget',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Live',
            'last_name' => 'Visitor',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
            'webchat_last_seen_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/mobile/conversations/{$conversation->uuid}/open-widget");
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.type', 'open_widget');
    }

    public function test_mobile_can_mark_conversation_as_read(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website Widget',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
            'unread_count' => 3,
        ]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Need help',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/mobile/conversations/{$conversation->uuid}/read");
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('unread_count', 0);

        $this->assertEquals(0, $conversation->fresh()->unread_count);
        $this->assertEquals('read', $msg->fresh()->status);
        Event::assertDispatched(MessageStatusUpdated::class);
    }

    public function test_mobile_can_update_contact_by_id(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'old@example.com',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/mobile/contacts/{$contact->id}", [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+1234567890',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'John Doe')
            ->assertJsonPath('first_name', 'John')
            ->assertJsonPath('last_name', 'Doe')
            ->assertJsonPath('email', 'john@example.com');

        $contact->refresh();
        $this->assertSame('John', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('john@example.com', $contact->email);
        $this->assertSame('+1234567890', $contact->phone_e164);
    }

    public function test_mobile_can_update_contact_by_conversation_uuid(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Customer',
            'last_name' => '100',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $response = $this->patchJson("/api/v1/mobile/conversations/{$conversation->uuid}/contact", [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Jane Smith')
            ->assertJsonPath('email', 'jane@example.com');

        $contact->refresh();
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Smith', $contact->last_name);
        $this->assertSame('jane@example.com', $contact->email);
    }

    public function test_mobile_agent_can_reply_with_document_attachment(): void
    {
        Storage::fake('public');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website Widget',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Mobile',
            'last_name' => 'User',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $pdf = UploadedFile::fake()->create('report.pdf', 300, 'application/pdf');

        $response = $this->post(
            "/api/v1/mobile/conversations/{$conversation->uuid}/reply",
            [
                'body' => 'Here is your monthly report',
                'type' => 'document',
                'attachment' => $pdf,
            ],
            ['Accept' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('message.type', 'document')
            ->assertJsonPath('message.body', 'Here is your monthly report');

        $this->assertNotEmpty($response->json('message.payload.preview_url'));
        $this->assertGreaterThan(0, $response->json('message.payload.file_size'));
    }

    public function test_mobile_agent_cannot_send_document_to_instagram(): void
    {
        Storage::fake('public');

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'display_name' => 'Instagram Account',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'IG',
            'last_name' => 'User',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $pdf = UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf');

        $response = $this->post(
            "/api/v1/mobile/conversations/{$conversation->uuid}/reply",
            [
                'body' => 'Here is the invoice',
                'type' => 'document',
                'attachment' => $pdf,
            ],
            ['Accept' => 'application/json']
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Instagram direct messaging only supports image, video, and audio attachments. Documents cannot be sent via Instagram DM.');
    }

    public function test_mobile_conversations_index_returns_contact_location_and_custom_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $channelAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website Widget',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Geo',
            'last_name' => 'Visitor',
            'custom_fields' => [
                'webchat_country' => 'Bangladesh',
                'webchat_country_code' => 'BD',
                'webchat_city' => 'Dhaka',
                'webchat_region' => 'Dhaka Division',
                'webchat_lat' => 23.8103,
                'webchat_lon' => 90.4125,
                'webchat_page_title' => 'Pricing Page',
                'webchat_page_url' => 'https://cerqlehub.com/pricing',
                'webchat_last_ip' => '103.100.100.1',
            ],
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $channelAccount->id,
            'status' => 'open',
            'webchat_last_seen_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/mobile/conversations');

        $response->assertOk()
            ->assertJsonPath('data.0.contact.location.country', 'Bangladesh')
            ->assertJsonPath('data.0.contact.location.country_code', 'BD')
            ->assertJsonPath('data.0.contact.location.city', 'Dhaka')
            ->assertJsonPath('data.0.contact.location.lat', 23.8103)
            ->assertJsonPath('data.0.contact.location.lon', 90.4125)
            ->assertJsonPath('data.0.contact.location.page_title', 'Pricing Page')
            ->assertJsonPath('data.0.contact.custom_fields.webchat_country', 'Bangladesh');
    }

    public function test_mobile_conversations_index_with_folder_all_and_pagination(): void
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['workspace_id' => $workspace->id]);
        $webchatAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Web Widget',
            'status' => 'active',
        ]);
        $waAccount = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'WhatsApp Line',
            'status' => 'active',
        ]);

        $contact1 = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Online',
            'last_name' => 'Visitor',
            'custom_fields' => ['webchat_country' => 'United States', 'webchat_city' => 'New York'],
        ]);

        $contact2 = Contact::create([
            'workspace_id' => $workspace->id,
            'first_name' => 'WA',
            'last_name' => 'Customer',
            'phone_e164' => '+15551234567',
        ]);

        $c1 = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact1->id,
            'channel_account_id' => $webchatAccount->id,
            'status' => 'open',
            'webchat_last_seen_at' => now(),
            'last_message_at' => now(),
        ]);

        $c2 = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact2->id,
            'channel_account_id' => $waAccount->id,
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/mobile/conversations?folder=all&page=1');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id', 'uuid', 'status', 'channel', 'is_online', 'webchat_last_seen_at',
                        'contact' => ['id', 'name', 'location'],
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'total', 'live_users_count'],
            ])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.live_users_count', 1);
    }
}
