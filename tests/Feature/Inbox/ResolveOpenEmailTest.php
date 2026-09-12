<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveOpenEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_resolution_is_scoped_to_workspace_mailbox_and_open_status(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        ['workspace' => $otherWorkspace] = $this->createSubscribedWorkspaceContext();
        $makeAccount = fn ($workspaceId, $channel = 'email') => ChannelAccount::create([
            'workspace_id' => $workspaceId, 'channel' => $channel,
            'display_name' => 'Test mailbox', 'status' => 'active',
        ]);
        $account = $makeAccount($workspace->id);
        $second = $makeAccount($workspace->id);
        $foreign = $makeAccount($otherWorkspace->id);
        $chat = $makeAccount($workspace->id, 'webchat');
        $makeThread = function ($account, $status = 'open') {
            $contact = Contact::create(['workspace_id' => $account->workspace_id, 'source' => $account->channel]);

            return Conversation::create([
                'workspace_id' => $account->workspace_id, 'channel_account_id' => $account->id,
                'contact_id' => $contact->id, 'status' => $status,
            ]);
        };
        $open = $makeThread($account);
        $preserved = $makeThread($account);
        $timestamp = now()->subDay()->startOfSecond();
        $preserved->update(['resolved_at' => $timestamp]);
        $untouched = [$makeThread($account, 'pending'), $makeThread($account, 'snoozed'), $makeThread($account, 'resolved'), $makeThread($second), $makeThread($foreign), $makeThread($chat)];

        $this->actingAs($user)->post(route('client.inbox.email.resolve-open'), ['account_id' => $account->id])
            ->assertRedirect()->assertSessionHas('success', 'Resolved 2 open email threads.');
        $this->assertSame('resolved', $open->fresh()->status);
        $this->assertNotNull($open->fresh()->resolved_at);
        $this->assertTrue($timestamp->equalTo($preserved->fresh()->resolved_at));
        foreach ($untouched as $thread) {
            $this->assertSame($thread->status, $thread->fresh()->status);
        }
        $this->post(route('client.inbox.email.resolve-open'), ['account_id' => $foreign->id])->assertNotFound();
        $this->post(route('client.inbox.email.resolve-open'), ['account_id' => $chat->id])->assertNotFound();
        $this->post(route('client.inbox.email.resolve-open'), ['account_id' => $account->id])
            ->assertSessionHas('success', 'Resolved 0 open email threads.');
        $this->post(route('client.inbox.email.resolve-open'))
            ->assertSessionHas('success', 'Resolved 1 open email threads.');
        $this->assertSame('open', $untouched[4]->fresh()->status);
        $this->assertSame('open', $untouched[5]->fresh()->status);
    }
}
