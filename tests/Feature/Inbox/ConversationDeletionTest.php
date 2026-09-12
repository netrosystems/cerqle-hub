<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeChat(int $workspaceId): Conversation
    {
        $account = ChannelAccount::create(['workspace_id' => $workspaceId, 'channel' => 'whatsapp', 'display_name' => 'Test', 'status' => 'active']);
        $contact = Contact::create(['workspace_id' => $workspaceId, 'source' => 'whatsapp']);
        $chat = Conversation::create(['workspace_id' => $workspaceId, 'channel_account_id' => $account->id, 'contact_id' => $contact->id, 'status' => 'open']);
        Message::create(['conversation_id' => $chat->id, 'channel' => 'whatsapp', 'direction' => 'in', 'type' => 'text', 'body' => 'Test', 'status' => 'delivered']);
        DB::table('inbox_notes')->insert(['conversation_id' => $chat->id, 'body' => 'Test', 'created_at' => now(), 'updated_at' => now()]);

        return $chat;
    }

    public function test_browser_deletes_chat_dependents_but_keeps_contact_and_other_chat(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $chat = $this->makeChat($workspace->id);
        $other = $this->makeChat($workspace->id);
        $this->actingAs($user)->delete(route('client.inbox.destroy', $chat->uuid))->assertRedirect(route('client.inbox.index'));
        $this->assertDatabaseMissing('conversations', ['id' => $chat->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $chat->id]);
        $this->assertDatabaseMissing('inbox_notes', ['conversation_id' => $chat->id]);
        $this->assertDatabaseHas('contacts', ['id' => $chat->contact_id]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $other->id]);
    }

    public function test_browser_and_mobile_reject_foreign_chats_and_mobile_returns_no_content(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        ['workspace' => $foreignWorkspace] = $this->createSubscribedWorkspaceContext();
        $foreign = $this->makeChat($foreignWorkspace->id);
        $own = $this->makeChat($workspace->id);
        $this->actingAs($user)->delete(route('client.inbox.destroy', $foreign->uuid))->assertForbidden();
        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/mobile/conversations/{$foreign->uuid}")->assertNotFound();
        $this->deleteJson("/api/v1/mobile/conversations/{$own->uuid}")->assertNoContent();
        $this->deleteJson("/api/v1/mobile/conversations/{$own->uuid}")->assertNotFound();
        $this->assertDatabaseHas('conversations', ['id' => $foreign->id]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $own->id]);
    }
}
