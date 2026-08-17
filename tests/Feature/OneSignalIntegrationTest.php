<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\NotificationPreference;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\NewMessageNotification;
use App\Services\OneSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OneSignalIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_managed_onesignal_configuration_takes_priority_over_environment(): void
    {
        config([
            'services.onesignal.app_id' => 'env-app-id',
            'services.onesignal.rest_api_key' => 'env-secret',
        ]);

        IntegrationConfig::create([
            'provider' => 'onesignal',
            'label' => 'OneSignal Push Notifications',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['app_id' => 'admin-app-id', 'rest_api_key' => 'admin-secret'],
        ]);

        $service = app(OneSignalService::class);

        $this->assertSame('admin-app-id', $service->publicAppId());
        $this->assertTrue($service->isConfigured());
    }

    public function test_disabled_admin_configuration_does_not_fall_back_to_environment(): void
    {
        config([
            'services.onesignal.app_id' => 'env-app-id',
            'services.onesignal.rest_api_key' => 'env-secret',
        ]);

        IntegrationConfig::create([
            'provider' => 'onesignal',
            'label' => 'OneSignal Push Notifications',
            'mode' => 'live',
            'enabled' => false,
            'credentials' => ['app_id' => 'admin-app-id', 'rest_api_key' => 'admin-secret'],
        ]);

        $service = app(OneSignalService::class);

        $this->assertSame('', $service->publicAppId());
        $this->assertFalse($service->isConfigured());
    }

    public function test_onesignal_replaces_vapid_for_new_message_notifications(): void
    {
        IntegrationConfig::create([
            'provider' => 'onesignal',
            'label' => 'OneSignal Push Notifications',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['app_id' => 'admin-app-id', 'rest_api_key' => 'admin-secret'],
        ]);

        $context = $this->createWorkspaceContext();
        $user = $context['user'];
        $contact = Contact::factory()->create(['workspace_id' => $context['workspace']->id]);
        $conversation = Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'assigned_user_id' => $user->id,
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'website',
            'body' => 'Hello',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        NotificationPreference::create([
            'user_id' => $user->id,
            'event' => 'new_message',
            'channel' => 'web_push',
            'enabled' => true,
        ]);

        $channels = (new NewMessageNotification($message, $conversation))->via($user);

        $this->assertContains(OneSignalChannel::class, $channels);
        $this->assertNotContains(WebPushChannel::class, $channels);
    }

    public function test_pushes_use_the_current_onesignal_endpoint_header_and_namespaced_identity(): void
    {
        IntegrationConfig::create([
            'provider' => 'onesignal',
            'label' => 'OneSignal Push Notifications',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['app_id' => 'app-id', 'rest_api_key' => 'rest-key'],
        ]);
        Http::fake(['https://api.onesignal.com/notifications' => Http::response(['id' => 'message-id'])]);

        app(OneSignalService::class)->sendToUser(
            42,
            'New message',
            'Hello',
            'https://cerqle.ai/inbox/1',
            1,
            ['screen' => 'master_email_inbox', 'conversation_uuid' => 'thread-uuid'],
        );

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.onesignal.com/notifications'
                && $request->hasHeader('Authorization', 'Key rest-key')
                && $request['include_aliases']['external_id'] === ['user:42']
                && $request['target_channel'] === 'push'
                && $request['web_url'] === 'https://cerqle.ai/inbox/1'
                && ! isset($request['url'])
                && $request['data']['screen'] === 'master_email_inbox'
                && $request['data']['conversation_uuid'] === 'thread-uuid'
                && $request['data']['conversation_id'] === 1;
        });
    }

    public function test_email_message_push_targets_the_master_email_inbox(): void
    {
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'display_name' => 'Support',
            'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $context['workspace']->id]);
        $conversation = Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'body' => 'A new customer email',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $payload = (new NewMessageNotification($message, $conversation))->toOneSignal($context['user']);

        $this->assertSame('master_email_inbox', $payload['screen']);
        $this->assertSame('email', $payload['channel']);
        $this->assertSame($context['workspace']->id, $payload['workspace_id']);
        $this->assertSame($conversation->uuid, $payload['conversation_uuid']);
        $this->assertSame($account->id, $payload['account_id']);
        $this->assertStringContainsString('/app/inbox/email', $payload['url']);
    }

    public function test_super_admins_are_never_sent_onesignal_pushes(): void
    {
        Http::fake();
        $admin = AdminUser::factory()->create();
        $notification = new class extends Notification
        {
            public function toOneSignal(object $notifiable): array
            {
                return ['title' => 'Test', 'body' => 'Test'];
            }
        };

        app(OneSignalChannel::class)->send($admin, $notification);

        Http::assertNothingSent();
    }
}
