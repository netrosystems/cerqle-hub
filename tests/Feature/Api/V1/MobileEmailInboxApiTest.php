<?php

namespace Tests\Feature\Api\V1;

use App\Models\Workspace;
use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class MobileEmailInboxApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_api_requires_mobile_authentication(): void
    {
        $this->getJson('/api/v1/mobile/email/threads')->assertUnauthorized();
    }

    public function test_mobile_profile_exposes_onesignal_bootstrap_identity(): void
    {
        IntegrationConfig::create([
            'provider' => 'onesignal',
            'label' => 'OneSignal Push Notifications',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['app_id' => 'native-app-id', 'rest_api_key' => 'rest-key'],
        ]);
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('mobile', ['*'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('push.enabled', true)
            ->assertJsonPath('push.app_id', 'native-app-id')
            ->assertJsonPath('push.external_id', 'user:'.$context['user']->id);
    }

    public function test_accounts_and_threads_are_email_only_workspace_scoped_and_trigger_throttled_sync(): void
    {
        Queue::fake();
        Cache::flush();
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('mobile', ['*'])->plainTextToken;
        [$account, $conversation] = $this->emailThread($context['workspace']->id);
        $otherAccount = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'webchat',
            'provider' => 'webchat',
            'display_name' => 'Website',
            'status' => 'active',
        ]);
        $otherContact = Contact::factory()->create(['workspace_id' => $context['workspace']->id]);
        Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'channel_account_id' => $otherAccount->id,
            'contact_id' => $otherContact->id,
            'status' => 'open',
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/mobile/email/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $account->id)
            ->assertJsonPath('data.0.email', 'support@example.com');

        $endpoint = '/api/v1/mobile/email/threads?account_id='.$account->id;
        $this->withToken($token)->getJson($endpoint)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $conversation->uuid)
            ->assertJsonPath('data.0.subject', 'Need assistance')
            ->assertJsonPath('counts.all', 1)
            ->assertJsonPath('sync.queued', true);
        $this->withToken($token)->getJson($endpoint)->assertOk()->assertJsonPath('sync.queued', false);

        Queue::assertPushed(SyncEmailAccountJob::class, 1);
    }

    public function test_thread_detail_marks_read_and_delta_messages_only_return_newer_email_messages(): void
    {
        Queue::fake();
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('mobile', ['*'])->plainTextToken;
        [, $conversation, $firstMessage] = $this->emailThread($context['workspace']->id);
        $secondMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'We can help.',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/email/threads/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('thread.subject', 'Need assistance')
            ->assertJsonCount(2, 'messages');
        $this->assertSame(0, $conversation->refresh()->unread_count);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/email/threads/{$conversation->uuid}/messages?after_id={$firstMessage->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $secondMessage->id)
            ->assertJsonPath('meta.last_id', $secondMessage->id);
    }

    public function test_mobile_agent_can_reply_resolve_reopen_and_manually_sync_an_email_thread(): void
    {
        Queue::fake();
        $context = $this->createWorkspaceContext();
        $token = $context['user']->createToken('mobile', ['*'])->plainTextToken;
        [$account, $conversation] = $this->emailThread($context['workspace']->id);
        $driver = new class implements ChannelDriverInterface
        {
            public function send(Message $message): string
            {
                return 'provider-email-2';
            }

            public function receiveWebhook(Request $request): array
            {
                return [];
            }

            public function verifyCreds(): bool
            {
                return true;
            }
        };
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->once()->with('email')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/email/threads/{$conversation->uuid}/reply", ['body' => 'Reply from the app'])
            ->assertOk()
            ->assertJsonPath('message.status', 'sent')
            ->assertJsonPath('message.body', 'Reply from the app')
            ->assertJsonPath('error', null);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'user_id' => $context['user']->id,
            'provider_message_id' => 'provider-email-2',
        ]);

        $this->withToken($token)
            ->patchJson("/api/v1/mobile/email/threads/{$conversation->uuid}/status", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('status', 'resolved');
        $this->assertNotNull($conversation->refresh()->resolved_at);

        $this->withToken($token)
            ->patchJson("/api/v1/mobile/email/threads/{$conversation->uuid}/status", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('status', 'open');
        $this->assertNull($conversation->refresh()->resolved_at);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/email/accounts/{$account->id}/sync")
            ->assertStatus(202)
            ->assertJsonPath('queued', true);
        Queue::assertPushed(SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => $job->manual);
    }

    public function test_mobile_email_api_cannot_read_another_workspace_mailbox_or_thread(): void
    {
        Queue::fake();
        $first = $this->createWorkspaceContext();
        $second = $this->createWorkspaceContext();
        $token = $first['user']->createToken('mobile', ['*'])->plainTextToken;
        [$foreignAccount, $foreignConversation] = $this->emailThread($second['workspace']->id);

        $this->withToken($token)
            ->getJson("/api/v1/mobile/email/threads/{$foreignConversation->uuid}")
            ->assertNotFound();
        $this->withToken($token)
            ->postJson("/api/v1/mobile/email/accounts/{$foreignAccount->id}/sync")
            ->assertNotFound();
    }

    public function test_mobile_agent_can_select_an_accessible_workspace_but_not_an_unrelated_workspace(): void
    {
        $first = $this->createWorkspaceContext();
        $second = Workspace::factory()->create([
            'client_id' => $first['client']->id,
            'owner_id' => $first['user']->id,
        ]);
        $foreign = $this->createWorkspaceContext();
        $token = $first['user']->createToken('mobile', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/mobile/workspaces/{$second->id}/select")
            ->assertOk()
            ->assertJsonPath('workspace.id', $second->id)
            ->assertJsonPath('workspace.role', 'owner');
        $this->assertSame($second->id, $first['user']->refresh()->workspace_id);

        $this->withToken($token)
            ->postJson("/api/v1/mobile/workspaces/{$foreign['workspace']->id}/select")
            ->assertForbidden();
    }

    private function emailThread(int $workspaceId): array
    {
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'business_account_id' => 'support-'.$workspaceId.'@example.com',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['username' => 'support@example.com'],
            'meta_json' => ['email' => 'support@example.com'],
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'email' => 'customer-'.$workspaceId.'@example.com',
        ]);
        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'unread_count' => 1,
            'last_message_at' => now()->subMinute(),
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Please help with my account.',
            'payload' => ['subject' => 'Need assistance'],
            'status' => 'received',
            'sent_at' => now()->subMinute(),
        ]);

        return [$account, $conversation, $message];
    }
}
