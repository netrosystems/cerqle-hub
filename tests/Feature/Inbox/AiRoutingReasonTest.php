<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\InboundReplyOwnership;
use App\Modules\Inbox\Services\AiRoutingReason;
use App\Modules\Inbox\Services\WidgetAiAvailability;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A bot that answers nothing must be able to say why.
 *
 * Every routing skip used to write a null reason, so "the widget is off", "the
 * chatbot was deleted" and "the schedule is malformed" were indistinguishable.
 */
class AiRoutingReasonTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $account;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->ctx = $this->createSubscribedWorkspaceContext();
        $workspaceId = $this->ctx['workspace']->id;

        $this->account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'display_name' => 'Site chat',
            'channel' => 'webchat',
            'provider' => 'webchat',
            'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);
        $this->chat = Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $this->account->id,
            'contact_id' => $contact->id,
            'assigned_to' => 'bot',
            'status' => 'open',
        ]);
    }

    public function test_a_handover_request_records_why_the_bot_stood_aside(): void
    {
        $inbound = $this->route('I want to talk to human support please');

        $this->assertSame(AiRoutingReason::HANDOVER_REQUESTED, $this->ownership($inbound)->reason_code);
        $this->assertStringContainsString('asked for a person', $this->ownership($inbound)->reason);
    }

    public function test_a_conversation_owned_by_a_person_is_named_as_such(): void
    {
        $this->chat->update(['assigned_user_id' => $this->ctx['user']->id]);

        $inbound = $this->route('Any update?');

        $this->assertSame(AiRoutingReason::HUMAN_OWNED, $this->ownership($inbound)->reason_code);
    }

    public function test_a_missing_widget_is_distinguishable_from_a_widget_that_is_switched_off(): void
    {
        $withoutWidget = $this->route('Hello there');
        $this->assertSame(AiRoutingReason::CHATBOT_MISSING, $this->ownership($withoutWidget)->reason_code);

        $bot = AiChatbot::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Bot', 'enabled' => true]);
        ChatWidget::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel_account_id' => $this->account->id,
            'name' => 'Site',
            'enabled' => true,
            'ai_enabled' => false,
            'ai_mode' => 'off',
            'ai_chatbot_id' => $bot->id,
        ]);

        $switchedOff = $this->route('Hello again');
        $this->assertSame(AiRoutingReason::AI_SWITCH_OFF, $this->ownership($switchedOff)->reason_code);
    }

    public function test_each_availability_failure_reports_its_own_reason(): void
    {
        $bot = AiChatbot::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Bot', 'enabled' => true]);
        $widget = ChatWidget::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel_account_id' => $this->account->id,
            'name' => 'Site',
            'enabled' => true,
            'ai_enabled' => true,
            'ai_mode' => 'permanent',
            'ai_chatbot_id' => $bot->id,
        ]);
        $availability = app(WidgetAiAvailability::class);

        $this->assertNull($availability->reason($widget), 'A permanently enabled widget is available.');

        $widget->ai_mode = 'off';
        $this->assertSame('mode_off', $availability->reason($widget));

        $widget->ai_mode = 'scheduled';
        $widget->ai_timezone = 'Not/AZone';
        $widget->ai_weekly_hours = app(WidgetAiAvailability::class)->defaults();
        // Previously swallowed: a malformed timezone left the bot permanently
        // and invisibly silent.
        $this->assertSame('schedule_error', $availability->reason($widget));

        $widget->ai_enabled = false;
        $this->assertSame('ai_switch_off', $availability->reason($widget));

        $widget->enabled = false;
        $this->assertSame('widget_disabled', $availability->reason($widget));
    }

    public function test_available_is_derived_from_the_reason_so_the_two_cannot_drift(): void
    {
        $widget = new ChatWidget(['workspace_id' => $this->ctx['workspace']->id, 'enabled' => false]);
        $availability = app(WidgetAiAvailability::class);

        $this->assertFalse($availability->available($widget));
        $this->assertNotNull($availability->reason($widget));
    }

    private function route(string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $this->chat->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => $body,
            'status' => 'received',
            'sent_at' => now(),
        ]);
        app(AutoReplyListener::class)->handle(new MessageReceived($message));

        return $message;
    }

    private function ownership(Message $message): InboundReplyOwnership
    {
        return InboundReplyOwnership::where('message_id', $message->id)->firstOrFail()->refresh();
    }
}
