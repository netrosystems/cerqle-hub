<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Inbox\Jobs\GenerateGroupedAiReply;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\InboundReplyOwnership;
use App\Modules\Inbox\Services\WidgetAiAvailability;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Services\ClientAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class WidgetAiAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private ChatWidget $widget;

    private Conversation $chat;

    private AiChatbot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00:00', 'UTC'));
        $this->ctx = $this->createSubscribedWorkspaceContext();
        $wid = $this->ctx['workspace']->id;
        $this->bot = AiChatbot::create(['workspace_id' => $wid, 'name' => 'Test bot', 'enabled' => true]);
        $account = ChannelAccount::create(['workspace_id' => $wid, 'channel' => 'webchat', 'status' => 'active', 'display_name' => 'QA']);
        $this->widget = ChatWidget::create(['workspace_id' => $wid, 'channel_account_id' => $account->id, 'name' => 'QA', 'position' => 'bottom_right', 'enabled' => true, 'ai_enabled' => true, 'ai_chatbot_id' => $this->bot->id, 'ai_mode' => 'scheduled', 'ai_timezone' => 'UTC', 'ai_weekly_hours' => app(WidgetAiAvailability::class)->defaults()]);
        $contact = Contact::factory()->create(['workspace_id' => $wid]);
        $this->chat = Conversation::create(['workspace_id' => $wid, 'channel_account_id' => $account->id, 'contact_id' => $contact->id, 'assigned_to' => 'bot', 'status' => 'open']);
    }

    private function inbound(): Message
    {
        return Message::create(['conversation_id' => $this->chat->id, 'channel' => 'webchat', 'direction' => 'in', 'type' => 'text', 'body' => 'Help', 'status' => 'received', 'sent_at' => now()]);
    }

    private function route(Message $message): void
    {
        app(AutoReplyListener::class)->handle(new MessageReceived($message));
    }

    private function data(): array
    {
        return ['name' => 'QA', 'position' => 'bottom_right', 'enabled' => true, 'ai_mode' => 'scheduled', 'ai_chatbot_id' => $this->bot->id, 'ai_timezone' => 'UTC', 'ai_weekly_hours' => app(WidgetAiAvailability::class)->defaults(), 'ai_revision' => $this->widget->ai_revision];
    }

    public function test_save_reopen_off_retention_and_stale_revision(): void
    {
        $route = route('client.inbox.chat-widgets.update', $this->widget);
        $this->actingAs($this->ctx['user'])->put($route, $this->data())->assertSessionHasNoErrors();
        $this->assertSame(2, $this->widget->fresh()->ai_revision);
        $this->actingAs($this->ctx['user'])->put($route, $this->data())->assertSessionHasErrors('ai_revision');
        $data = [...$this->data(), 'ai_revision' => 2, 'ai_mode' => 'off'];
        $this->actingAs($this->ctx['user'])->put($route, $data)->assertSessionHasNoErrors();
        $saved = $this->widget->fresh();
        $this->assertFalse($saved->ai_enabled);
        $this->assertEquals($this->bot->id, $saved->ai_chatbot_id);
        $this->assertEquals($data['ai_weekly_hours'], $saved->ai_weekly_hours);
        $this->actingAs($this->ctx['user'])->get(route('client.inbox.chat-widgets.edit', $saved))->assertOk();
    }

    public function test_invalid_bot_timezone_empty_hours_and_equal_window_are_rejected(): void
    {
        $other = $this->createSubscribedWorkspaceContext();
        $bot = AiChatbot::create(['workspace_id' => $other['workspace']->id, 'name' => 'Foreign', 'enabled' => true]);
        $route = route('client.inbox.chat-widgets.update', $this->widget);
        foreach ([['ai_chatbot_id' => $bot->id], ['ai_chatbot_id' => null], ['ai_timezone' => 'Invalid/Zone']] as $patch) {
            $this->actingAs($this->ctx['user'])->put($route, [...$this->data(), ...$patch])->assertSessionHasErrors();
        }
        $hours = $this->data()['ai_weekly_hours'];
        $hours[0]['windows'][0]['end'] = '09:00';
        $this->actingAs($this->ctx['user'])->put($route, [...$this->data(), 'ai_weekly_hours' => $hours])->assertSessionHasErrors();
        foreach ($hours as &$day) {
            $day['enabled'] = false;
        }
        unset($day);
        $this->actingAs($this->ctx['user'])->put($route, [...$this->data(), 'ai_weekly_hours' => $hours])->assertSessionHasErrors('ai_weekly_hours');
        $this->actingAs($other['user'])->put($route, $this->data())->assertForbidden();
    }

    public function test_split_hours_boundaries_timezone_and_overnight(): void
    {
        $service = app(WidgetAiAvailability::class);
        $hours = $service->defaults();
        $hours[0]['windows'] = [['start' => '09:00', 'end' => '12:00'], ['start' => '14:00', 'end' => '17:00']];
        $this->widget->ai_weekly_hours = $hours;
        foreach (['09:00' => true, '12:00' => false, '13:00' => false, '14:00' => true, '17:00' => false] as $time => $active) {
            $this->assertSame($active, $service->available($this->widget, CarbonImmutable::parse("2026-09-14 $time", 'UTC')));
        }
        $hours[0]['windows'] = [['start' => '22:00', 'end' => '02:00']];
        $this->widget->ai_weekly_hours = $hours;
        $this->widget->ai_timezone = 'Asia/Dhaka';
        $this->assertTrue($service->available($this->widget, CarbonImmutable::parse('2026-09-14 19:00', 'UTC')));
        $this->assertFalse($service->available($this->widget, CarbonImmutable::parse('2026-09-14 20:00', 'UTC')));
    }

    public function test_overlaps_include_week_wrap_and_all_day(): void
    {
        $service = app(WidgetAiAvailability::class);
        $hours = $service->defaults();
        $hours[6] = ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '22:00', 'end' => '10:00']]];
        $this->expectException(ValidationException::class);
        $service->validateHours($hours, 'UTC', true);
    }

    public function test_all_day_dst_and_disabled_days(): void
    {
        $service = app(WidgetAiAvailability::class);
        $hours = array_map(fn ($day) => [...$day, 'enabled' => false], $service->defaults());
        $hours[6] = ['enabled' => true, 'all_day' => false, 'windows' => [['start' => '01:00', 'end' => '03:00']]];
        $this->widget->ai_weekly_hours = $hours;
        $this->widget->ai_timezone = 'America/New_York';
        foreach (['2026-03-08 06:30', '2026-11-01 05:30', '2026-11-01 06:30'] as $at) {
            $this->assertTrue($service->available($this->widget, CarbonImmutable::parse($at, 'UTC')));
        }
        $hours[6]['all_day'] = true;
        $this->widget->ai_weekly_hours = $hours;
        $this->assertTrue($service->available($this->widget, CarbonImmutable::parse('2026-03-08 20:00', 'UTC')));
        $this->assertFalse($service->available($this->widget, CarbonImmutable::parse('2026-03-09 20:00', 'UTC')));
    }

    public function test_legacy_migration_preserves_on_off_and_selected_bot(): void
    {
        $this->widget->update(['ai_enabled' => true]);
        $off = ChatWidget::create(['workspace_id' => $this->widget->workspace_id, 'name' => 'Off QA', 'ai_enabled' => false]);
        $migration = require database_path('migrations/2026_09_13_160000_add_widget_ai_availability.php');
        $migration->down();
        $migration->up();
        $this->assertSame('permanent', $this->widget->fresh()->ai_mode);
        $this->assertSame('off', $off->fresh()->ai_mode);
        $this->assertEquals($this->bot->id, $this->widget->fresh()->ai_chatbot_id);
    }

    public function test_all_day_overrides_incomplete_windows_and_five_window_limit(): void
    {
        $service = app(WidgetAiAvailability::class);
        $hours = $service->defaults();
        $hours[0]['all_day'] = true;
        $hours[0]['windows'] = [['start' => '', 'end' => '']];
        $this->assertSame([], $service->validateHours($hours, 'UTC', true)[0]['windows']);
        $hours[0]['all_day'] = false;
        $hours[0]['windows'] = array_fill(0, 6, ['start' => '09:00', 'end' => '10:00']);
        $this->expectException(ValidationException::class);
        $service->validateHours($hours, 'UTC', true);
    }

    public function test_human_takeover_off_and_disconnection_during_generation_stop_send(): void
    {
        foreach (['human', 'off', 'disconnected'] as $change) {
            $this->chat->update(['assigned_to' => 'bot']);
            $this->widget->update(['ai_mode' => 'scheduled', 'ai_enabled' => true]);
            $this->chat->channelAccount->update(['status' => 'active']);
            $message = $this->inbound();
            $this->route($message);
            $runner = Mockery::mock(ChatbotRunner::class);
            $runner->shouldReceive('run')->once()->andReturnUsing(function () use ($change) {
                match ($change) {
                    'human' => $this->chat->update(['assigned_to' => 'human']),
                    'off' => $this->widget->update(['ai_mode' => 'off', 'ai_enabled' => false]),
                    'disconnected' => $this->chat->channelAccount->update(['status' => 'inactive']),
                };

                return 'Answer';
            });
            $channels = Mockery::mock(ChannelManager::class);
            $channels->shouldNotReceive('driver');
            (new GenerateGroupedAiReply($message->id, $this->widget->workspace_id, $this->widget->channel_account_id, $this->bot->id, null, $this->widget->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
            $this->assertSame('skipped', InboundReplyOwnership::where('message_id', $message->id)->value('status'));
        }
    }

    public function test_independent_widget_configuration_and_disabled_widget(): void
    {
        $other = ChatWidget::create(['workspace_id' => $this->widget->workspace_id, 'ai_enabled' => true, 'ai_chatbot_id' => $this->bot->id, 'enabled' => true, 'ai_mode' => 'permanent']);
        $this->widget->update(['ai_enabled' => false, 'ai_mode' => 'off']);
        $service = app(WidgetAiAvailability::class);
        $this->assertTrue($service->available($other));
        $this->assertFalse($service->available($this->widget));
        $other->enabled = false;
        $this->assertFalse($service->available($other));
    }

    public function test_duplicate_inbound_queues_once_using_widget_not_metadata(): void
    {
        $message = $this->inbound();
        $this->route($message);
        $this->route($message);
        Queue::assertPushed(GenerateGroupedAiReply::class, 1);
        Queue::assertPushed(GenerateGroupedAiReply::class, fn ($job) => $job->widgetId === $this->widget->id && $job->widgetRevision === 1);
    }

    public function test_outside_hours_and_off_do_not_queue_catchup(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 18:00', 'UTC'));
        $message = $this->inbound();
        $this->route($message);
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00', 'UTC'));
        $this->route($message);
        $this->widget->update(['ai_mode' => 'off', 'ai_enabled' => false]);
        $this->route($this->inbound());
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
    }

    public function test_revision_change_and_old_unpinned_webchat_jobs_skip(): void
    {
        $message = $this->inbound();
        $this->route($message);
        $this->widget->update(['ai_revision' => 2]);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldNotReceive('run');
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        (new GenerateGroupedAiReply($message->id, $this->widget->workspace_id, $this->widget->channel_account_id, $this->bot->id, null, $this->widget->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertSame('skipped', InboundReplyOwnership::where('message_id', $message->id)->value('status'));
        $new = $this->inbound();
        $this->route($new);
        (new GenerateGroupedAiReply($new->id, $this->widget->workspace_id, $this->widget->channel_account_id, $this->bot->id, null))->handle($runner, $channels, app(ClientAccessService::class));
    }

    public function test_boundary_crossed_during_generation_prevents_send(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 16:59:50', 'UTC'));
        $message = $this->inbound();
        $this->route($message);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function () {
            $this->travelTo(CarbonImmutable::parse('2026-09-14 17:00:00', 'UTC'));

            return 'Answer';
        });
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        (new GenerateGroupedAiReply($message->id, $this->widget->workspace_id, $this->widget->channel_account_id, $this->bot->id, null, $this->widget->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertSame('skipped', InboundReplyOwnership::where('message_id', $message->id)->value('status'));
    }

    public function test_available_reply_sends_once_and_public_poll_refreshes_availability(): void
    {
        $message = $this->inbound();
        $this->route($message);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('run')->once()->andReturn('Test answer');
        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->once()->andReturn('webchat-test');
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldReceive('driver')->once()->with('webchat')->andReturn($driver);
        $job = new GenerateGroupedAiReply($message->id, $this->widget->workspace_id, $this->widget->channel_account_id, $this->bot->id, null, $this->widget->id, 1);
        $job->handle($runner, $channels, app(ClientAccessService::class));
        $job->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertSame('completed', InboundReplyOwnership::where('message_id', $message->id)->value('status'));
        $session = $this->postJson(route('widget.session'), ['key' => $this->widget->widget_key, 'visitor_id' => 'availability-qa'])->assertOk()->assertJsonPath('config.ai_availability.active', true);
        $this->travelTo(CarbonImmutable::parse('2026-09-14 18:00', 'UTC'));
        $this->withHeaders(['X-Widget-Token' => $session->json('token')])->getJson(route('widget.poll', ['key' => $this->widget->widget_key]))->assertOk()->assertJsonPath('ai_availability.active', false);
    }
}
