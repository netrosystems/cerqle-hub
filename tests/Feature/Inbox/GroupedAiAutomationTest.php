<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Listeners\AutoReplyListener;
use App\Models\ClientSubscription;
use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Automation\Models\Automation;
use App\Modules\Inbox\Jobs\GenerateGroupedAiReply;
use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Inbox\Models\AiAutomationSetting;
use App\Modules\Inbox\Models\InboundReplyOwnership;
use App\Modules\Inbox\Services\AiAutomationSchedule;
use App\Modules\Inbox\Services\AiAutomationSettings;
use App\Modules\Inbox\Services\EmailDriver;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\GoogleGmailClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
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
use Mockery;
use Tests\TestCase;

class GroupedAiAutomationTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    private AiChatbot $bot;

    private ChannelAccount $account;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->ctx = $this->createSubscribedWorkspaceContext();
        $wid = $this->ctx['workspace']->id;
        $this->bot = AiChatbot::create(['workspace_id' => $wid, 'name' => 'Support', 'enabled' => true]);
        $this->account = ChannelAccount::create(['workspace_id' => $wid, 'display_name' => 'Test mailbox', 'channel' => 'email', 'provider' => 'gmail', 'status' => 'active', 'meta_json' => ['email' => 'support@example.test']]);
        $contact = Contact::factory()->create(['workspace_id' => $wid, 'email' => 'customer@example.test']);
        $this->chat = Conversation::create(['workspace_id' => $wid, 'channel_account_id' => $this->account->id, 'contact_id' => $contact->id, 'assigned_to' => 'human', 'status' => 'open']);
    }

    private function data(string $mode = 'on'): array
    {
        return ['mode' => $mode, 'chatbot_id' => $this->bot->id, 'timezone' => 'Asia/Dhaka', 'weekly_hours' => app(AiAutomationSchedule::class)->defaults(), 'revision' => 0];
    }

    private function setting(string $mode = 'on'): AiAutomationSetting
    {
        return AiAutomationSetting::create(array_merge($this->data($mode), ['workspace_id' => $this->ctx['workspace']->id, 'group' => 'email', 'revision' => 1, 'activated_at' => now()->subMinute()]));
    }

    private function inbound(array $payload = [], string $body = 'Help'): Message
    {
        return Message::create(['conversation_id' => $this->chat->id, 'direction' => 'in', 'channel' => 'email', 'type' => 'text', 'body' => $body, 'payload' => $payload, 'status' => 'received', 'sent_at' => now()]);
    }

    private function route(Message $message): void
    {
        app(AutoReplyListener::class)->handle(new MessageReceived($message));
    }

    private function execute(Message $message, ?callable $duringGeneration = null, bool $failSend = false): void
    {
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('run')->once()->andReturnUsing(function () use ($duringGeneration) {
            if ($duringGeneration) {
                $duringGeneration();
            }

            return 'Answer';
        });
        $driver = Mockery::mock(ChannelDriverInterface::class);
        if ($duringGeneration) {
            $driver->shouldNotReceive('send');
        } elseif ($failSend) {
            $driver->shouldReceive('send')->once()->andThrow(new \RuntimeException('timeout'));
        } else {
            $driver->shouldReceive('send')->once()->andReturn('provider-test-id');
        }
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldReceive('driver')->andReturn($driver);
        $job = new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1);
        $job->handle($runner, $channels, app(ClientAccessService::class));
        $job->handle($runner, $channels, app(ClientAccessService::class));
    }

    public function test_group_save_is_isolated_revisioned_and_off_retains_configuration(): void
    {
        $this->actingAs($this->ctx['user'])->patch(route('client.inbox.ai-automation.update', ['group' => 'email']), $this->data())->assertSessionHasNoErrors();
        $setting = app(AiAutomationSettings::class)->find($this->ctx['workspace']->id, 'email');
        $this->assertSame(1, $setting->revision);
        $this->assertNull(app(AiAutomationSettings::class)->find($this->ctx['workspace']->id, 'channels'));
        $this->actingAs($this->ctx['user'])->patch(route('client.inbox.ai-automation.update', ['group' => 'email']), $this->data())->assertSessionHasErrors('revision');
        $off = $this->data('off');
        $off['revision'] = 1;
        $off['chatbot_id'] = null;
        $this->patch(route('client.inbox.ai-automation.update', ['group' => 'email']), $off)->assertSessionHasNoErrors();
        $this->assertEquals($this->bot->id, $setting->fresh()->chatbot_id);
        $this->assertSame('off', $setting->fresh()->mode);
    }

    public function test_invalid_bot_hours_timezone_and_unauthenticated_save_are_rejected(): void
    {
        $url = route('client.inbox.ai-automation.update', ['group' => 'email']);
        $this->patch($url, $this->data())->assertRedirect(route('login'));
        $other = $this->createSubscribedWorkspaceContext();
        $foreign = AiChatbot::create(['workspace_id' => $other['workspace']->id, 'name' => 'Foreign', 'enabled' => true]);
        $data = $this->data();
        $data['chatbot_id'] = $foreign->id;
        $this->actingAs($this->ctx['user'])->patch($url, $data)->assertSessionHasErrors('chatbot_id');
        $data = $this->data('scheduled');
        $data['timezone'] = 'Invalid';
        $this->patch($url, $data)->assertSessionHasErrors('timezone');
        $data = $this->data('scheduled');
        $data['weekly_hours'][0]['end'] = '09:00';
        $this->patch($url, $data)->assertSessionHasErrors('weekly_hours.0.end');
        $data['weekly_hours'] = array_fill(0, 7, ['enabled' => false, 'all_day' => false, 'start' => '09:00', 'end' => '17:00']);
        $this->patch($url, $data)->assertSessionHasErrors('weekly_hours');
        $this->assertDatabaseCount('workspace_ai_automation_settings', 0);
    }

    public function test_schedule_boundaries_overnight_all_day_timezone_and_dst(): void
    {
        $schedule = app(AiAutomationSchedule::class);
        $hours = $schedule->defaults();
        $this->assertTrue($schedule->active($hours, 'Asia/Dhaka', CarbonImmutable::parse('2026-09-14 03:00Z')));
        $this->assertFalse($schedule->active($hours, 'Asia/Dhaka', CarbonImmutable::parse('2026-09-14 11:00Z')));
        $hours[0]['start'] = '22:00';
        $hours[0]['end'] = '02:00';
        $hours[1]['enabled'] = false;
        $this->assertTrue($schedule->active($hours, 'UTC', CarbonImmutable::parse('2026-09-15 01:59Z')));
        $this->assertFalse($schedule->active($hours, 'UTC', CarbonImmutable::parse('2026-09-15 02:00Z')));
        $hours[6] = ['enabled' => true, 'all_day' => true, 'start' => '09:00', 'end' => '17:00'];
        $this->assertTrue($schedule->active($hours, 'America/New_York', CarbonImmutable::parse('2026-11-01 05:30Z')));
        $this->assertTrue($schedule->active($hours, 'America/New_York', CarbonImmutable::parse('2026-11-01 06:30Z')));
        $this->assertTrue($schedule->active($hours, 'America/New_York', CarbonImmutable::parse('2026-03-08 07:30Z')));
    }

    public function test_default_email_triage_replies_once_in_existing_thread(): void
    {
        $this->setting();
        $message = $this->inbound();
        $this->route($message);
        $this->route($message);
        Queue::assertPushed(GenerateGroupedAiReply::class, 1);
        $this->execute($message);
        $this->assertDatabaseCount('inbound_reply_ownerships', 1);
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'completed']);
        $this->assertSame(1, $this->chat->messages()->where('direction', 'out')->count());
    }

    public function test_historical_automated_self_mail_and_attachment_only_do_not_queue(): void
    {
        $this->setting();
        foreach ([['history_import' => true], ['mail_headers' => ['auto-submitted' => 'auto-replied']], ['mail_headers' => ['list-id' => 'list']], ['from_address' => 'support@example.test'], ['from_address' => 'mailer-daemon@example.test']] as $payload) {
            $this->route($this->inbound($payload));
        }
        $this->route($this->inbound(['has_attachments' => true], ''));
        $old = $this->inbound();
        $old->update(['sent_at' => now()->subDay()]);
        $this->route($old);
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
    }

    public function test_takeover_or_settings_change_during_generation_stops_send(): void
    {
        $this->setting();
        $message = $this->inbound();
        $this->route($message);
        $this->execute($message, fn () => $this->chat->update(['handover_at' => now()]));
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'skipped']);
        $this->assertSame(0, $this->chat->messages()->where('direction', 'out')->count());
    }

    public function test_changed_revision_skips_queue(): void
    {
        $setting = $this->setting();
        $message = $this->inbound();
        $this->route($message);
        $setting->update(['revision' => 2]);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldNotReceive('run');
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        (new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'skipped']);
    }

    public function test_provider_timeout_requires_review_and_duplicate_job_does_not_send(): void
    {
        $this->setting();
        $message = $this->inbound();
        $this->route($message);
        $this->execute($message, null, true);
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'delivery_review']);
        $this->assertDatabaseHas('messages', ['direction' => 'out', 'status' => 'failed']);
    }

    public function test_workflow_owns_inbound_before_ai(): void
    {
        $this->setting();
        Automation::create(['workspace_id' => $this->ctx['workspace']->id, 'name' => 'Existing flow', 'status' => 'active', 'trigger_type' => 'message.received', 'trigger_config' => [], 'nodes' => [['id' => 't', 'type' => 'trigger', 'data' => []], ['id' => 'end', 'type' => 'add_tag', 'data' => ['tag' => 'Test']]], 'edges' => [['source' => 't', 'target' => 'end']]]);
        $message = $this->inbound();
        $this->route($message);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseHas('inbound_reply_ownerships', ['owner' => 'workflow']);
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
    }

    public function test_legacy_assignment_preserved_until_group_is_saved(): void
    {
        $this->account->update(['meta_json' => ['email' => 'support@example.test', 'ai_chatbot_id' => $this->bot->id]]);
        $this->assertTrue(app(AiAutomationSettings::class)->page($this->ctx['workspace']->id, 'email')['legacy']);
        $this->route($this->inbound());
        Queue::assertPushed(GenerateGroupedAiReply::class);
        Queue::fake();
        $this->setting('off');
        $this->route($this->inbound());
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
    }

    public function test_disconnected_sender_and_expired_subscription_do_not_generate(): void
    {
        config(['saas.enforce_client_subscription' => true]);
        $this->setting();
        foreach (['disconnect', 'expire'] as $case) {
            $message = $this->inbound();
            $this->route($message);
            if ($case === 'disconnect') {
                $this->account->update(['status' => 'inactive']);
            } else {
                ClientSubscription::where('client_id', $this->ctx['client']->id)->update(['status' => 'expired', 'ends_at' => now()->subDay()]);
            }
            $runner = Mockery::mock(ChatbotRunner::class);
            $runner->shouldNotReceive('run');
            $channels = Mockery::mock(ChannelManager::class);
            $channels->shouldNotReceive('driver');
            (new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
            $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'skipped']);
            $this->account->update(['status' => 'active']);
        }
    }

    public function test_assignment_and_manual_reply_block_ai_but_explicit_handback_allows_it(): void
    {
        $this->setting();
        $this->chat->update(['assigned_user_id' => $this->ctx['user']->id]);
        $this->route($this->inbound());
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
        $this->chat->update(['assigned_user_id' => null, 'assigned_to' => 'bot']);
        Message::create(['conversation_id' => $this->chat->id, 'direction' => 'out', 'channel' => 'email', 'type' => 'text', 'body' => 'Manual', 'sent_by' => 'human', 'status' => 'sent', 'sent_at' => now()]);
        $this->route($this->inbound());
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
        $this->chat->forceFill(['assigned_to' => 'bot', 'handover_at' => null, 'ai_handback_after_message_id' => $this->chat->messages()->max('id')])->save();
        $this->route($this->inbound());
        Queue::assertPushed(GenerateGroupedAiReply::class, 1);
    }

    public function test_new_mailbox_inherits_group_bot_and_foreign_account_job_is_rejected(): void
    {
        $this->setting();
        $second = ChannelAccount::create(['workspace_id' => $this->ctx['workspace']->id, 'display_name' => 'Second', 'channel' => 'email', 'provider' => 'imap_smtp', 'status' => 'active']);
        $this->chat->update(['channel_account_id' => $second->id]);
        $message = $this->inbound();
        $this->route($message);
        Queue::assertPushed(GenerateGroupedAiReply::class, fn ($job) => $job->accountId === $second->id && $job->chatbotId === $this->bot->id);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldNotReceive('run');
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        (new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'skipped']);
    }

    public function test_interrupted_attempt_requires_review_instead_of_replaying(): void
    {
        $this->setting();
        $message = $this->inbound();
        $this->route($message);
        InboundReplyOwnership::where('message_id', $message->id)->update(['status' => 'sending']);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldNotReceive('run');
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        $job = new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1);
        $job->handle($runner, $channels, app(ClientAccessService::class));
        $job->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'delivery_review']);
        $this->assertSame(1, $this->chat->messages()->where('direction', 'out')->count());
    }

    public function test_queued_response_never_survives_an_off_change_during_generation(): void
    {
        $setting = $this->setting();
        $message = $this->inbound();
        $this->route($message);
        $this->execute($message, fn () => $setting->update(['mode' => 'off', 'revision' => 2]));
        $this->assertDatabaseHas('inbound_reply_ownerships', ['message_id' => $message->id, 'status' => 'skipped']);
    }

    public function test_email_drivers_use_original_anchor_and_sender_only(): void
    {
        $original = $this->inbound(['subject' => 'Original', 'internet_message_id' => 'original@test', 'provider_thread_id' => 'original-thread']);
        $original->update(['provider_message_id' => 'original-provider-id']);
        $this->inbound(['subject' => 'Later', 'internet_message_id' => 'later@test', 'provider_thread_id' => 'later-thread']);
        $out = Message::create(['conversation_id' => $this->chat->id, 'direction' => 'out', 'channel' => 'email', 'type' => 'text', 'body' => 'Answer', 'payload' => ['reply_to_message_id' => $original->id], 'status' => 'queued', 'sent_by' => 'bot', 'sent_at' => now()]);
        foreach (['gmail', 'microsoft_365', 'imap_smtp'] as $provider) {
            $this->account->update(['provider' => $provider]);
            $out->unsetRelation('conversation');
            $google = Mockery::mock(GoogleGmailClient::class);
            $microsoft = Mockery::mock(MicrosoftGraphMailClient::class);
            $generic = Mockery::mock(GenericMailboxClient::class);
            if ($provider === 'gmail') {
                $google->shouldReceive('send')->once()->withArgs(fn ($account, $to, $subject, $body, $reference, $thread, $attachments) => $account->id === $this->account->id && $to === 'customer@example.test' && $subject === 'Re: Original' && $reference === 'original@test' && $thread === 'original-thread' && $attachments === [])->andReturn('gmail-id');
            } elseif ($provider === 'microsoft_365') {
                $microsoft->shouldReceive('sendReply')->once()->withArgs(fn ($account, $id, $body, $to) => $account->id === $this->account->id && $id === 'original-provider-id' && $to === 'customer@example.test')->andReturn('graph-id');
            } else {
                $generic->shouldReceive('send')->once()->withArgs(fn ($account, $to, $subject, $body, $reference, $cc, $bcc, $attachments) => $account->id === $this->account->id && $to === 'customer@example.test' && $subject === 'Re: Original' && $reference === 'original@test' && $cc === [] && $bcc === [] && $attachments === [])->andReturn('imap-id');
            }
            $this->assertNotEmpty((new EmailDriver($microsoft, $google, $generic))->send($out));
        }
    }

    public function test_initial_sync_suppresses_ai_then_fresh_sync_queues_once(): void
    {
        $this->setting();
        $item = ['id' => 'first', 'conversationId' => 'thread', 'subject' => 'Help', 'from' => ['emailAddress' => ['address' => 'sync@example.test']], 'body' => ['content' => 'Hello'], 'receivedDateTime' => now()->toIso8601String()];
        $google = Mockery::mock(GoogleGmailClient::class);
        $google->shouldReceive('syncInbox')->andReturn([$item]);
        $microsoft = Mockery::mock(MicrosoftGraphMailClient::class);
        $generic = Mockery::mock(GenericMailboxClient::class);
        $job = new SyncEmailAccountJob($this->account->id);
        $job->handle($microsoft, $google, $generic);
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
        $this->assertTrue(Message::where('provider_message_id', 'first')->first()->payload['history_import']);
        $this->account->update(['meta_json' => ['email' => 'support@example.test', 'last_synced_at' => now()->toIso8601String()]]);
        $fresh = $item;
        $fresh['id'] = 'second';
        $google2 = Mockery::mock(GoogleGmailClient::class);
        $google2->shouldReceive('syncInbox')->andReturn([$item, $fresh]);
        $job->handle($microsoft, $google2, $generic);
        $job->handle($microsoft, $google2, $generic);
        Queue::assertPushed(GenerateGroupedAiReply::class, 1);
        $this->assertSame(2, Message::where('channel', 'email')->count());
    }

    public function test_outside_schedule_does_not_accumulate_ai_jobs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-14 20:00 UTC'));
        $this->setting('scheduled');
        $this->route($this->inbound());
        Queue::assertNotPushed(GenerateGroupedAiReply::class);
        $this->travelBack();
    }

    public function test_credits_failure_is_visible_and_does_not_send_fallback(): void
    {
        $this->setting();
        $this->bot->update(['fallback_reply' => 'Fallback']);
        $message = $this->inbound();
        $this->route($message);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldReceive('run')->once()->andThrow(new AiCreditsExhaustedException);
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        (new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertDatabaseHas('messages', ['direction' => 'out', 'status' => 'failed', 'body' => '']);
        $this->assertDatabaseHas('inbound_reply_ownerships', ['status' => 'failed']);
    }

    public function test_grouped_messaging_channels_queue_selected_bot_without_account_links(): void
    {
        AiAutomationSetting::create(array_merge($this->data(), ['workspace_id' => $this->ctx['workspace']->id, 'group' => 'channels', 'revision' => 1, 'activated_at' => now()->subMinute()]));
        foreach (['whatsapp', 'instagram', 'messenger'] as $channel) {
            $this->account->update(['channel' => $channel]);
            $this->chat->update(['assigned_to' => 'bot']);
            $message = Message::create(['conversation_id' => $this->chat->id, 'direction' => 'in', 'channel' => $channel, 'type' => 'text', 'body' => 'Help', 'status' => 'received', 'sent_at' => now()]);
            $this->route($message);
        }
        Queue::assertPushed(GenerateGroupedAiReply::class, 3);
    }

    public function test_disabled_bot_records_failure_in_thread(): void
    {
        $this->setting();
        $this->bot->update(['enabled' => false]);
        $message = $this->inbound();
        $this->route($message);
        $runner = Mockery::mock(ChatbotRunner::class);
        $runner->shouldNotReceive('run');
        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldNotReceive('driver');
        (new GenerateGroupedAiReply($message->id, $this->ctx['workspace']->id, $this->account->id, $this->bot->id, 1))->handle($runner, $channels, app(ClientAccessService::class));
        $this->assertDatabaseHas('inbound_reply_ownerships', ['status' => 'failed']);
        $this->assertDatabaseHas('messages', ['direction' => 'out', 'status' => 'failed']);
    }
}
