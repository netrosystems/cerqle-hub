<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\AiReplyEligibility;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * What a customer experiences when they ask for a person and nobody is there.
 *
 * Previously: silence. The conversation was queued and the team notified, but
 * the customer saw nothing, and the bot fell permanently silent the instant the
 * handover was recorded — so at 3am they were left with no reply at all.
 */
class NoAgentAvailableTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChannelAccount $account;

    private Conversation $chat;

    private ChatWidget $widget;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('ai.smart_bot.no_agent_holding_reply', true);

        $this->ctx = $this->createSubscribedWorkspaceContext();
        $workspaceId = $this->ctx['workspace']->id;

        $this->account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'display_name' => 'Site chat',
            'channel' => 'webchat',
            'provider' => 'webchat',
            'status' => 'active',
        ]);
        $bot = AiChatbot::create(['workspace_id' => $workspaceId, 'name' => 'Bot', 'enabled' => true]);
        $this->widget = ChatWidget::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $this->account->id,
            'name' => 'Site',
            'enabled' => true,
            'ai_enabled' => true,
            'ai_mode' => 'permanent',
            'ai_chatbot_id' => $bot->id,
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

    public function test_a_handover_outside_working_hours_acknowledges_the_customer_at_zero_credits(): void
    {
        $this->closeTheOffice();
        $this->fakeSender()->shouldReceive('send')->once()->andReturn('provider-id');

        $this->route('I want to talk to human support');

        $outbound = Message::where('conversation_id', $this->chat->id)->where('direction', 'out')->first();
        $this->assertNotNull($outbound, 'The customer should not be met with silence.');
        $this->assertSame('bot', $outbound->sent_by);
        $this->assertDatabaseCount('ai_credit_usages', 0);
        $this->assertNotNull($this->chat->fresh()->handover_at, 'The handover still happens.');
    }

    public function test_the_clients_own_offline_message_is_used_verbatim(): void
    {
        $this->closeTheOffice();
        $this->widget->update(['offline_message' => 'Wir sind gerade offline.']);
        $this->fakeSender()->shouldReceive('send')->once()->andReturn('provider-id');

        $this->route('connect me to a real person');

        $outbound = Message::where('conversation_id', $this->chat->id)->where('direction', 'out')->firstOrFail();
        // Their own wording, in their own language, needing no translation.
        $this->assertStringContainsString('Wir sind gerade offline.', $outbound->body);
    }

    public function test_nothing_extra_is_sent_when_the_team_is_open(): void
    {
        $this->fakeSender()->shouldNotReceive('send');

        $this->route('talk to agent');

        $this->assertSame(0, Message::where('conversation_id', $this->chat->id)->where('direction', 'out')->count());
    }

    public function test_the_bot_keeps_answering_until_a_person_actually_replies(): void
    {
        config()->set('ai.smart_bot.bot_continues_after_unanswered_handoff', true);
        $this->closeTheOffice();
        $this->chat->update(['assigned_to' => 'human', 'handover_at' => now()]);
        $eligibility = app(AiReplyEligibility::class);

        $this->assertFalse(
            $eligibility->humanOwned($this->chat->fresh(), 'webchat'),
            'With nobody available and no human reply yet, the bot should keep helping.',
        );

        // A person joins: the bot stands down immediately.
        Message::create([
            'conversation_id' => $this->chat->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Hi, taking over from here.',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $this->assertTrue($eligibility->humanOwned($this->chat->fresh(), 'webchat'));
    }

    public function test_an_explicit_assignment_always_silences_the_bot(): void
    {
        config()->set('ai.smart_bot.bot_continues_after_unanswered_handoff', true);
        $this->closeTheOffice();
        $this->chat->update(['assigned_user_id' => $this->ctx['user']->id, 'handover_at' => now()]);

        $this->assertTrue(app(AiReplyEligibility::class)->humanOwned($this->chat->fresh(), 'webchat'));
    }

    public function test_the_bot_still_stands_down_when_the_flag_is_off(): void
    {
        config()->set('ai.smart_bot.bot_continues_after_unanswered_handoff', false);
        $this->closeTheOffice();
        $this->chat->update(['handover_at' => now()]);

        $this->assertTrue(app(AiReplyEligibility::class)->humanOwned($this->chat->fresh(), 'webchat'));
    }

    /** Closes every day, so "now" is always outside working hours. */
    private function closeTheOffice(): void
    {
        $schedule = [];
        foreach (['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $day) {
            $schedule[$day] = ['enabled' => false, 'open' => '09:00', 'close' => '17:00'];
        }
        $this->widget->update(['working_hours_json' => ['enabled' => true, 'timezone' => 'UTC', 'schedule' => $schedule]]);
        $this->chat->refresh();
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

    private function fakeSender(): ChannelDriverInterface
    {
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $channels);

        return $driver;
    }
}
