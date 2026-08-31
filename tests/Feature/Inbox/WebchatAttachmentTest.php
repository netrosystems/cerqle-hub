<?php

namespace Tests\Feature\Inbox;

use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\WebchatDriver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebchatAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_send_an_image_to_a_website_chat_without_whatsapp(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Website visitor',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'visitor-1',
            'last_message_at' => now(),
        ]);

        $image = UploadedFile::fake()->image('reply.png', 200, 200);
        $response = $this->actingAs($user)->post(
            route('client.inbox.reply', $conversation),
            ['body' => 'Here is the screenshot', 'type' => 'image', 'attachment' => $image],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('message.user_id', $user->id)
            ->assertJsonPath('message.sender.name', $user->name);

        $message = Message::where('conversation_id', $conversation->id)->sole();
        $this->assertSame('webchat', $message->channel);
        $this->assertSame('image', $message->type);
        $this->assertSame('sent', $message->status);
        $this->assertNotEmpty($message->payload['preview_url'] ?? null);
        $this->assertArrayNotHasKey('media_id', $message->payload ?? []);
        $storageManager = app(StorageManager::class);
        $files = $storageManager->disk()->allFiles($storageManager->prefixedPath('message-media'));
        $this->assertNotEmpty($files);
    }

    public function test_agent_audio_is_saved_as_a_playable_voice_message_instead_of_a_filename(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Customer 1',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'visitor-audio',
            'last_message_at' => now(),
        ]);

        $audio = UploadedFile::fake()->create('recording.wav', 32, 'audio/wav');
        $response = $this->actingAs($user)->post(
            route('client.inbox.reply', $conversation),
            ['type' => 'audio', 'attachment' => $audio],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()->assertJsonPath('error', null);

        $message = Message::where('conversation_id', $conversation->id)->sole();
        $this->assertSame('audio', $message->type);
        $this->assertSame('Voice message', $message->body);
        $this->assertSame('recording.wav', $message->payload['filename']);
        $this->assertNotEmpty($message->payload['preview_url'] ?? null);
    }

    public function test_agent_can_send_a_document_to_a_website_chat(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Website visitor',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'visitor-doc-1',
            'last_message_at' => now(),
        ]);

        $pdf = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');
        $response = $this->actingAs($user)->post(
            route('client.inbox.reply', $conversation),
            ['body' => 'Please review this contract', 'type' => 'document', 'attachment' => $pdf],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('message.user_id', $user->id);

        $message = Message::where('conversation_id', $conversation->id)->sole();
        $this->assertSame('webchat', $message->channel);
        $this->assertSame('document', $message->type);
        $this->assertSame('Please review this contract', $message->body);
        $this->assertSame('contract.pdf', $message->payload['filename']);
        $this->assertSame('application/pdf', $message->payload['mime_type']);
        $this->assertNotEmpty($message->payload['preview_url'] ?? null);
        $this->assertGreaterThan(0, $message->payload['file_size']);
        $storageManager = app(StorageManager::class);
        $files = $storageManager->disk()->allFiles($storageManager->prefixedPath('message-media'));
        $this->assertNotEmpty($files);
    }

    public function test_agent_cannot_send_document_to_instagram(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'instagram',
            'display_name' => 'Instagram Page',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'instagram',
            'first_name' => 'IG Follower',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'ig-thread-1',
            'last_message_at' => now(),
        ]);

        $pdf = UploadedFile::fake()->create('pricing.pdf', 200, 'application/pdf');
        $response = $this->actingAs($user)->post(
            route('client.inbox.reply', $conversation),
            ['body' => 'Here is the pricing', 'type' => 'document', 'attachment' => $pdf],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('Instagram direct messaging only supports image, video, and audio', $response->json('error'));
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_attachment_exceeding_10mb_is_rejected(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'workspace_id' => $workspace->id,
            'source' => 'webchat',
            'first_name' => 'Website visitor',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'external_thread_id' => 'visitor-large-1',
            'last_message_at' => now(),
        ]);

        // 11 MB file exceeds 10 MB limit (10240 KB)
        $largePdf = UploadedFile::fake()->create('huge_manual.pdf', 11264, 'application/pdf');
        $response = $this->actingAs($user)->post(
            route('client.inbox.reply', $conversation),
            ['body' => 'Here is the huge file', 'type' => 'document', 'attachment' => $largePdf],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['attachment']);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->count());
    }

    public function test_widget_visitor_can_upload_pdf_document(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        Storage::fake('public');

        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        // Create session
        $sessionRes = $this->postJson('/widget/v1/session', [
            'key' => $widget->widget_key,
        ]);
        $sessionRes->assertOk();
        $token = $sessionRes->json('token');

        $pdf = UploadedFile::fake()->create('feedback.pdf', 300, 'application/pdf');
        $msgRes = $this->withHeaders(['X-Widget-Token' => $token])->post(
            '/widget/v1/messages',
            [
                'key' => $widget->widget_key,
                'type' => 'document',
                'client_message_id' => 'client-msg-123',
                'message' => 'Attached is my feedback',
                'attachment' => $pdf,
            ],
            ['Accept' => 'application/json']
        );

        $msgRes->assertOk()
            ->assertJsonPath('message.type', 'document')
            ->assertJsonPath('message.filename', 'feedback.pdf')
            ->assertJsonPath('message.body', 'Attached is my feedback');

        $this->assertNotEmpty($msgRes->json('message.attachment_url'));
        $this->assertGreaterThan(0, $msgRes->json('message.file_size'));
    }

    public function test_website_visitors_are_not_automatically_opted_into_marketing(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ]);

        $conversation = app(WebchatDriver::class)->resolveConversation($widget, 'anonymous-visitor');
        $contact = $conversation->contact;

        $this->assertFalse($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertFalse($contact->opt_in_email);
    }
}
