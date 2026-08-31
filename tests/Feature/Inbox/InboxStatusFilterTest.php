<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxStatusFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_view_includes_open_resolved_and_snoozed_conversations(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);

        foreach (['open', 'resolved', 'snoozed'] as $status) {
            $contact = Contact::create([
                'workspace_id' => $workspace->id,
                'source' => 'webchat',
                'first_name' => ucfirst($status),
            ]);
            $conversation = Conversation::create([
                'workspace_id' => $workspace->id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'status' => $status,
                'external_thread_id' => 'visitor-'.$status,
                'last_message_at' => now(),
            ]);
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'webchat',
                'type' => 'text',
                'body' => 'Hello',
                'status' => 'delivered',
                'sent_at' => now(),
            ]);
        }

        $this->actingAs($user)->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('conversations.total', 3)
            );

        $this->actingAs($user)->get(route('client.inbox.index', ['folder' => 'resolved']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conversations.total', 1));

        $this->actingAs($user)->get(route('client.inbox.index', ['folder' => 'snoozed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('conversations.total', 1));
    }

    public function test_omni_channel_inbox_excludes_email_and_sms_everywhere(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        foreach (['webchat', 'whatsapp', 'email', 'sms'] as $channel) {
            $account = ChannelAccount::create([
                'workspace_id' => $workspace->id,
                'channel' => $channel,
                'provider' => $channel,
                'display_name' => ucfirst($channel),
                'status' => 'active',
            ]);
            $contact = Contact::create([
                'workspace_id' => $workspace->id,
                'source' => $channel,
                'first_name' => ucfirst($channel),
            ]);
            $conversation = Conversation::create([
                'workspace_id' => $workspace->id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'status' => 'open',
                'last_message_at' => now(),
            ]);
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => $channel,
                'type' => 'text',
                'body' => 'Hello',
                'status' => 'delivered',
                'sent_at' => now(),
            ]);
        }

        $this->actingAs($user)->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/Index')
                ->where('conversations.total', 2)
                ->has('channelAccounts', 2)
                ->where('channelAccounts.0.channel', 'webchat')
                ->where('channelAccounts.1.channel', 'whatsapp'));

        $this->actingAs($user)
            ->getJson(route('client.inbox.poll', ['channel' => 'email']))
            ->assertOk()
            ->assertJsonPath('conversations.total', 0);

        $this->actingAs($user)
            ->getJson(route('client.inbox.channel-accounts'))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonMissing(['channel' => 'email'])
            ->assertJsonMissing(['channel' => 'sms']);
    }
}
