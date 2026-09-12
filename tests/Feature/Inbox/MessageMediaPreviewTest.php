<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageMediaPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_cached_image_is_served_privately_without_redirecting_to_a_broken_public_url(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Storage::fake('public');
        $account = ChannelAccount::create(['workspace_id' => $workspace->id, 'channel' => 'whatsapp', 'display_name' => 'Test', 'status' => 'active']);
        $contact = Contact::create(['workspace_id' => $workspace->id, 'source' => 'whatsapp']);
        $conversation = Conversation::create(['workspace_id' => $workspace->id, 'channel_account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $message = Message::create([
            'conversation_id' => $conversation->id, 'channel' => 'whatsapp', 'direction' => 'in', 'type' => 'image',
            'payload' => ['preview_url' => '/missing-public-link.png', 'mime_type' => 'image/png'],
        ]);
        $storage = app(StorageManager::class);
        $storage->disk()->put($storage->prefixedPath("message-media/{$message->id}.png"), 'test-image-bytes');
        $response = $this->actingAs($user)->get(route('client.inbox.message-media', ['conversation' => $conversation->uuid, 'message' => $message->id]));
        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('test-image-bytes', $response->streamedContent());
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));

        ['user' => $foreign] = $this->createSubscribedWorkspaceContext();
        $this->actingAs($foreign)->get(route('client.inbox.message-media', ['conversation' => $conversation->uuid, 'message' => $message->id]))->assertForbidden();
    }
}
