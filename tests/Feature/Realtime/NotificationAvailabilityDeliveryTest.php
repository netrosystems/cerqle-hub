<?php

namespace Tests\Feature\Realtime;

use App\Models\NotificationAvailability;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Inbox\Services\AiAutomationSchedule;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\AutomationFailedNotification;
use App\Notifications\CampaignCompletedNotification;
use App\Notifications\Channels\AvailabilityBroadcastChannel;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\ConversationAssignedNotification;
use App\Notifications\ConversationHandoverNotification;
use App\Notifications\Events\AvailabilityBroadcastNotificationCreated;
use App\Notifications\MentionedInNoteNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\PendingCustomerReplyNotification;
use App\Notifications\WorkspaceExportReadyNotification;
use App\Services\NotificationDeliveryPolicy;
use App\Services\OneSignalService;
use App\Services\UserPushTokenService;
use App\Services\WebPushService;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class NotificationAvailabilityDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function setting(array $context, string $mode): NotificationAvailability
    {
        return NotificationAvailability::create([
            'user_id' => $context['user']->id,
            'workspace_id' => $context['workspace']->id,
            'mode' => $mode,
            'timezone' => 'UTC',
            'weekly_hours' => app(AiAutomationSchedule::class)->defaults(),
        ]);
    }

    private function conversation(array $context): Conversation
    {
        $contact = Contact::factory()->create(['workspace_id' => $context['workspace']->id]);

        return Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    public function test_message_originating_outside_hours_never_catches_up_inside_hours(): void
    {
        $context = $this->createWorkspaceContext();
        $this->setting($context, 'scheduled');
        $conversation = $this->conversation($context);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in', 'channel' => 'whatsapp', 'body' => 'After hours',
            'status' => 'sent', 'created_at' => '2026-09-14 08:00:00',
        ]);
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');
        $notification = new NewMessageNotification($message, $conversation);
        $this->assertSame(['database', 'broadcast'], $notification->via($context['user']));
        $this->assertFalse($notification->shouldSend($context['user'], OneSignalChannel::class));
        $this->assertTrue($notification->toBroadcast($context['user'])->data['silent']);
    }

    public function test_dispatch_paused_then_always_preserves_history_without_catch_up(): void
    {
        $context = $this->createWorkspaceContext();
        $setting = $this->setting($context, 'paused');
        $notification = new WorkspaceExportReadyNotification('https://example.test/export', $context['workspace']->id);
        $this->assertSame(['database'], $notification->via($context['user']));
        $queued = unserialize(serialize($notification));
        $setting->update(['mode' => 'always']);
        $this->assertFalse($queued->shouldSend($context['user'], 'mail'));
        $this->assertFalse($queued->shouldSend($context['user'], OneSignalChannel::class));
        Notification::sendNow($context['user'], $queued, ['database']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($context['workspace']->id, $context['user']->notifications()->first()->data['workspace_id']);
    }

    public function test_recipient_pins_are_independent(): void
    {
        $context = $this->createWorkspaceContext();
        $other = User::factory()->create([
            'workspace_id' => $context['workspace']->id,
            'client_id' => $context['client']->id,
        ]);
        $this->setting($context, 'paused');
        $notification = new WorkspaceExportReadyNotification('https://example.test/export', $context['workspace']->id);
        $this->assertSame(['database'], $notification->via($context['user']));
        $this->assertContains('mail', $notification->via($other));
        $this->assertFalse($notification->shouldSend($context['user'], 'mail'));
        $this->assertTrue($notification->shouldSend($other, 'mail'));
    }

    public function test_broadcast_worker_rechecks_silent_and_preserves_wire_event_name(): void
    {
        $context = $this->createWorkspaceContext();
        $setting = $this->setting($context, 'always');
        $notification = new ConversationAssignedNotification($this->conversation($context), null);
        $notification->via($context['user']);
        $event = new AvailabilityBroadcastNotificationCreated($context['user'], $notification, $notification->toBroadcast($context['user'])->data);
        $this->assertFalse($event->data['silent']);
        $job = unserialize(serialize(new BroadcastEvent($event)));
        $setting->update(['mode' => 'paused']);
        $broadcaster = \Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->once()->withArgs(fn ($channels, $name, $payload) => count($channels) === 1 && $name === BroadcastNotificationCreated::class && $payload['silent'] === true);
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with(null)->andReturn($broadcaster);
        $job->handle($factory);
        $this->assertInstanceOf(AvailabilityBroadcastChannel::class, app(BroadcastChannel::class));
    }

    public function test_broadcast_worker_preserves_initial_silence_after_resume(): void
    {
        $context = $this->createWorkspaceContext();
        $setting = $this->setting($context, 'paused');
        $notification = new ConversationAssignedNotification($this->conversation($context), null);
        $notification->via($context['user']);
        $event = new AvailabilityBroadcastNotificationCreated($context['user'], $notification, $notification->toBroadcast($context['user'])->data);
        $setting->update(['mode' => 'always']);
        $this->assertTrue($event->broadcastWith()['silent']);
    }

    public function test_fresh_membership_and_status_override_stale_recipient_models(): void
    {
        $context = $this->createWorkspaceContext();
        $user = User::factory()->create(['workspace_id' => null]);
        $context['workspace']->members()->attach($user, ['role' => 'agent']);
        $notification = new WorkspaceExportReadyNotification('https://example.test/export', $context['workspace']->id);
        $this->assertContains('mail', $notification->via($user));
        $context['workspace']->members()->detach($user);
        $this->assertFalse($notification->shouldSend($user, 'mail'));
        $this->assertFalse($notification->shouldSend($user, 'broadcast'));
        $context['workspace']->members()->attach($user, ['role' => 'agent']);
        User::whereKey($user->id)->update(['status' => 'inactive']);
        $this->assertFalse($notification->shouldSend($user, 'mail'));
        $this->assertTrue($notification->shouldSend($user, 'database'));
    }

    public function test_mail_event_and_final_send_guard_recheck_availability_and_preferences(): void
    {
        $context = $this->createWorkspaceContext();
        $setting = $this->setting($context, 'always');
        $notification = new WorkspaceExportReadyNotification('https://example.test/export', $context['workspace']->id);
        $notification->via($context['user']);
        $mailData = $notification->toMail($context['user'])->data();
        $setting->update(['mode' => 'paused']);
        $this->assertFalse(Event::until(new NotificationSending($context['user'], $notification, 'mail')));
        $this->assertFalse(Event::until(new MessageSending(new Email, $mailData)));
        $setting->update(['mode' => 'always']);
        NotificationPreference::create([
            'user_id' => $context['user']->id, 'event' => 'workspace_export_ready',
            'channel' => 'mail', 'enabled' => false,
        ]);
        $this->assertFalse(Event::until(new MessageSending(new Email, $mailData)));
        $this->assertNull(Event::until(new MessageSending(new Email)));
    }

    public function test_direct_push_channel_cannot_bypass_paused_policy(): void
    {
        $context = $this->createWorkspaceContext();
        $this->setting($context, 'paused');
        $conversation = $this->conversation($context);
        $message = new Message(['body' => 'hello', 'channel' => 'whatsapp']);
        $service = \Mockery::mock(WebPushService::class);
        $service->shouldNotReceive('sendToUser');
        (new WebPushChannel($service))->send($context['user'], new NewMessageNotification($message, $conversation));
    }

    public function test_direct_onesignal_channel_rechecks_before_each_provider_call(): void
    {
        $context = $this->createWorkspaceContext();
        $setting = $this->setting($context, 'always');
        $notification = new WorkspaceExportReadyNotification('https://example.test/export', $context['workspace']->id);
        $notification->via($context['user']);
        $service = \Mockery::mock(OneSignalService::class);
        $service->shouldReceive('isConfigured')->once()->andReturn(true);
        $service->shouldReceive('sendToSubscriptionIds')->once()->andReturnUsing(function () use ($setting) {
            $setting->update(['mode' => 'paused']);

            return true;
        });
        $service->shouldNotReceive('sendToExternalId');
        $tokens = \Mockery::mock(UserPushTokenService::class);
        $tokens->shouldReceive('activeTokensFor')->once()->andReturn(['subscription-id']);
        (new OneSignalChannel($service, $tokens))->send($context['user'], $notification);
    }

    public function test_broadcast_channel_queues_guarded_event_and_worker_drops_revoked_membership(): void
    {
        $context = $this->createWorkspaceContext();
        $user = User::factory()->create(['workspace_id' => null]);
        $context['workspace']->members()->attach($user, ['role' => 'agent']);
        $notification = new ConversationAssignedNotification($this->conversation($context), null);
        Event::fake([AvailabilityBroadcastNotificationCreated::class]);
        Notification::sendNow($user, $notification, ['broadcast']);
        $context['workspace']->members()->detach($user);
        $factory = \Mockery::mock(Factory::class);
        $factory->shouldNotReceive('connection');
        Event::assertDispatched(AvailabilityBroadcastNotificationCreated::class, function ($event) use ($factory) {
            (new BroadcastEvent($event))->handle($factory);

            return $event->broadcastOn() === [];
        });
    }

    public function test_unscoped_export_and_unrelated_account_notifications_bypass_policy(): void
    {
        $context = $this->createWorkspaceContext();
        $this->setting($context, 'paused');
        $notification = new WorkspaceExportReadyNotification('https://example.test/export');
        $this->assertContains('mail', $notification->via($context['user']));
        $this->assertTrue($notification->shouldSend($context['user'], OneSignalChannel::class));
        $this->assertTrue(app(NotificationDeliveryPolicy::class)->allows($context['user'], new \Illuminate\Notifications\Notification, 'mail'));
    }

    public function test_all_eight_work_notification_types_retain_database_history_while_paused(): void
    {
        $context = $this->createWorkspaceContext();
        $this->setting($context, 'paused');
        $conversation = $this->conversation($context);
        $message = new Message(['body' => 'Retained message', 'channel' => 'whatsapp']);
        $campaign = new Campaign([
            'workspace_id' => $context['workspace']->id, 'name' => 'Retained campaign',
        ]);
        $automation = new Automation([
            'workspace_id' => $context['workspace']->id, 'name' => 'Retained automation',
        ]);
        $run = new AutomationRun;
        $run->setRelation('automation', $automation);
        $notifications = [
            new NewMessageNotification($message, $conversation),
            new ConversationAssignedNotification($conversation, null),
            new MentionedInNoteNotification($context['user'], $conversation, 'Retained note'),
            new ConversationHandoverNotification($conversation),
            new PendingCustomerReplyNotification($conversation, $message),
            new CampaignCompletedNotification($campaign),
            new AutomationFailedNotification($run, 'Retained failure'),
            new WorkspaceExportReadyNotification('https://example.test/export', $context['workspace']->id),
        ];

        foreach ($notifications as $notification) {
            $channels = $notification->via($context['user']);
            $this->assertContains('database', $channels, $notification::class);
            $this->assertNotContains('mail', $channels, $notification::class);
            $this->assertNotContains(OneSignalChannel::class, $channels, $notification::class);
            $this->assertNotContains(WebPushChannel::class, $channels, $notification::class);
            $this->assertFalse($notification->shouldSend($context['user'], 'mail'), $notification::class);
            if (method_exists($notification, 'toBroadcast')) {
                $this->assertContains('broadcast', $channels, $notification::class);
                $this->assertTrue($notification->toBroadcast($context['user'])->data['silent'], $notification::class);
            }
            Notification::sendNow($context['user'], $notification, ['database']);
        }

        $history = $context['user']->notifications()->get();
        $this->assertCount(8, $history);
        $this->assertCount(8, $history->pluck('data.type')->unique());
        foreach ($history as $record) {
            $this->assertSame($context['workspace']->id, $record->data['workspace_id']);
            $this->assertTrue($record->data['silent']);
        }
    }
}
