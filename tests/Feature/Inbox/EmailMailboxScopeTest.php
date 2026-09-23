<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\AiAutomationSetting;
use App\Modules\Inbox\Models\InboundReplyOwnership;
use App\Modules\Inbox\Services\AiAutomationSchedule;
use App\Modules\Inbox\Services\AiRoutingReason;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailMailboxScopeTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private AiChatbot $bot;

    private ChannelAccount $sales;

    private ChannelAccount $billing;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->ctx = $this->createSubscribedWorkspaceContext();
        $wid = $this->ctx['workspace']->id;
        $this->bot = AiChatbot::create(['workspace_id' => $wid, 'name' => 'Support', 'enabled' => true]);
        $this->sales = ChannelAccount::create(['workspace_id' => $wid, 'display_name' => 'Sales', 'channel' => 'email', 'provider' => 'gmail', 'status' => 'active', 'meta_json' => ['email' => 'sales@example.test']]);
        $this->billing = ChannelAccount::create(['workspace_id' => $wid, 'display_name' => 'Billing', 'channel' => 'email', 'provider' => 'gmail', 'status' => 'active', 'meta_json' => ['email' => 'billing@example.test']]);
    }

    private function setting(?array $mailboxIds): AiAutomationSetting
    {
        return AiAutomationSetting::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'group' => 'email',
            'mode' => 'on',
            'chatbot_id' => $this->bot->id,
            'mailbox_ids' => $mailboxIds,
            'timezone' => 'Asia/Dhaka',
            'weekly_hours' => app(AiAutomationSchedule::class)->defaults(),
            'revision' => 1,
            'activated_at' => now()->subMinute(),
        ]);
    }

    private function inboundTo(ChannelAccount $account, array $payload = []): Message
    {
        $wid = $this->ctx['workspace']->id;
        $contact = Contact::factory()->create(['workspace_id' => $wid, 'email' => 'customer@example.test']);
        $chat = Conversation::create(['workspace_id' => $wid, 'channel_account_id' => $account->id, 'contact_id' => $contact->id, 'assigned_to' => 'human', 'status' => 'open']);
        $message = Message::create(['conversation_id' => $chat->id, 'direction' => 'in', 'channel' => 'email', 'type' => 'text', 'body' => 'My order has not arrived, can you check?', 'payload' => array_merge(['from_address' => 'customer@example.test'], $payload), 'status' => 'received', 'sent_at' => now()]);
        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        return $message;
    }

    private function ownership(Message $message): ?InboundReplyOwnership
    {
        return InboundReplyOwnership::where('message_id', $message->id)->first();
    }

    public function test_no_selection_means_every_mailbox_as_it_did_before(): void
    {
        // Every workspace already using this feature has a null scope, and must
        // keep answering on all of its mailboxes without touching the setting.
        $this->setting(null);

        foreach ([$this->sales, $this->billing] as $mailbox) {
            $this->assertSame(AiRoutingReason::QUEUED, $this->ownership($this->inboundTo($mailbox))?->reason_code);
        }
    }

    public function test_only_the_chosen_mailbox_is_answered(): void
    {
        $this->setting([$this->sales->id]);

        $this->assertSame(AiRoutingReason::QUEUED, $this->ownership($this->inboundTo($this->sales))?->reason_code);

        $skipped = $this->ownership($this->inboundTo($this->billing));
        $this->assertSame(AiRoutingReason::MAILBOX_NOT_SELECTED, $skipped?->reason_code);
        $this->assertSame('skipped', $skipped?->status);
        // The operator has to be able to see why, not just that nothing happened.
        $this->assertStringContainsString('mailbox', strtolower((string) $skipped?->reason));
    }

    public function test_a_newsletter_is_not_answered_even_on_a_selected_mailbox(): void
    {
        $this->setting([$this->sales->id]);

        $message = $this->inboundTo($this->sales, ['mail_headers' => ['list-id' => '<news.example.test>']]);

        $this->assertSame(AiRoutingReason::EMAIL_NOT_AN_INQUIRY, $this->ownership($message)?->reason_code);
    }

    public function test_an_uncertain_email_is_still_answered(): void
    {
        // The product decision: doubt means answer and flag, never silence.
        $this->setting(null);

        $message = $this->inboundTo($this->sales, ['from_address' => 'updates@partner.test']);

        $this->assertSame(AiRoutingReason::QUEUED, $this->ownership($message)?->reason_code);
    }

    public function test_the_controller_rejects_a_mailbox_from_another_workspace(): void
    {
        $other = $this->createSubscribedWorkspaceContext();
        $foreign = ChannelAccount::create(['workspace_id' => $other['workspace']->id, 'display_name' => 'Theirs', 'channel' => 'email', 'provider' => 'gmail', 'status' => 'active']);

        $this->actingAs($this->ctx['user']);
        $response = $this->patch(route('client.inbox.ai-automation.update', 'email'), [
            'mode' => 'on',
            'chatbot_id' => $this->bot->id,
            'timezone' => 'Asia/Dhaka',
            'weekly_hours' => app(AiAutomationSchedule::class)->defaults(),
            'revision' => 0,
            'mailbox_ids' => [$this->sales->id, $foreign->id],
        ]);

        $response->assertSessionHasErrors('mailbox_ids');
        $this->assertDatabaseCount('workspace_ai_automation_settings', 0);
    }

    public function test_turning_it_on_with_no_mailbox_chosen_is_rejected(): void
    {
        $this->actingAs($this->ctx['user']);
        $response = $this->patch(route('client.inbox.ai-automation.update', 'email'), [
            'mode' => 'on',
            'chatbot_id' => $this->bot->id,
            'timezone' => 'Asia/Dhaka',
            'weekly_hours' => app(AiAutomationSchedule::class)->defaults(),
            'revision' => 0,
            'mailbox_ids' => [],
        ]);

        // Silently saving "on, for nothing" would look enabled and never reply.
        $response->assertSessionHasErrors('mailbox_ids');
    }

    public function test_a_valid_selection_is_saved(): void
    {
        $this->actingAs($this->ctx['user']);
        $this->patch(route('client.inbox.ai-automation.update', 'email'), [
            'mode' => 'on',
            'chatbot_id' => $this->bot->id,
            'timezone' => 'Asia/Dhaka',
            'weekly_hours' => app(AiAutomationSchedule::class)->defaults(),
            'revision' => 0,
            'mailbox_ids' => [$this->billing->id],
        ])->assertSessionHasNoErrors();

        $setting = AiAutomationSetting::where('workspace_id', $this->ctx['workspace']->id)->where('group', 'email')->first();
        $this->assertSame([$this->billing->id], $setting->mailbox_ids);
    }
}
