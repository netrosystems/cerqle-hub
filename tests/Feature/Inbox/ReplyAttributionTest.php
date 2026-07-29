<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplyAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_list_and_thread_identify_the_teammate_who_replied(): void
    {
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'I can help with that.',
            'status' => 'sent',
            'sent_by' => 'human',
            'user_id' => $user->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('client.inbox.poll'))
            ->assertOk()
            ->assertJsonPath('conversations.data.0.last_human_reply.user.id', $user->id)
            ->assertJsonPath('conversations.data.0.last_human_reply.user.name', $user->name);

        $this->actingAs($user)
            ->getJson(route('client.inbox.messages.poll', [
                'conversation' => $conversation->uuid,
                'after' => $message->id - 1,
            ]))
            ->assertOk()
            ->assertJsonPath('messages.0.user.id', $user->id)
            ->assertJsonPath('messages.0.user.name', $user->name);
    }
}
