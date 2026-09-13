<?php

namespace Tests\Feature\Client;

use App\Models\InternalNote;
use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlainInternalNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_keep_at_text_without_mentions_or_alerts(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        User::factory()->create(['name' => 'Teammate', 'workspace_id' => $ctx['workspace']->id]);
        $chat = Conversation::create(['workspace_id' => $ctx['workspace']->id, 'channel_account_id' => 1, 'contact_id' => 1, 'status' => 'open']);
        $old = InternalNote::create(['conversation_id' => $chat->id, 'user_id' => $ctx['user']->id, 'body' => 'Existing note', 'mentioned_user_ids' => [123]]);
        Notification::fake();
        $this->actingAs($ctx['user'])->postJson(route('client.inbox.notes.store', $chat), ['body' => 'Follow up with @Teammate and support@example.test'])
            ->assertCreated()->assertJsonPath('body', 'Follow up with @Teammate and support@example.test')->assertJsonPath('mentioned_user_ids', []);
        Notification::assertNothingSent();
        $this->assertSame([123], $old->fresh()->mentioned_user_ids);
        $this->getJson(route('client.inbox.notes.index', $chat))->assertOk()->assertJsonCount(2);
        $this->postJson(route('client.inbox.notes.store', $chat), ['body' => ''])->assertUnprocessable();
        $this->postJson(route('client.inbox.notes.store', $chat), ['body' => str_repeat('x', 4097)])->assertUnprocessable();
        $foreign = Conversation::create(['workspace_id' => $this->createWorkspaceContext()['workspace']->id, 'channel_account_id' => 1, 'contact_id' => 1, 'status' => 'open']);
        $this->postJson(route('client.inbox.notes.store', $foreign), ['body' => 'No access'])->assertForbidden();
    }
}
