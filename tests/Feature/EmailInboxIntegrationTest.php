<?php

namespace Tests\Feature;

use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\GoogleGmailClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class EmailInboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_mailbox_is_saved_only_after_both_connections_are_verified(): void
    {
        Queue::fake();
        $context = $this->createWorkspaceContext();
        $client = Mockery::mock(GenericMailboxClient::class);
        $client->shouldReceive('verify')->once()->withArgs(function (ChannelAccount $account): bool {
            $this->assertFalse($account->exists);
            $this->assertSame('imap_smtp', $account->provider);
            $this->assertSame('ssl', $account->credentials['smtp_encryption']);

            return true;
        })->andReturnTrue();
        $this->app->instance(GenericMailboxClient::class, $client);

        $this->actingAs($context['user'])->post(route('client.inbox.email.generic.store'), [
            'email' => 'support@example.com',
            'display_name' => 'Support',
            'imap_host' => 'mail.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'mail.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => 'support@example.com',
            'password' => 'secret-password',
            'verify_tls' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $account = ChannelAccount::where('provider', 'imap_smtp')->firstOrFail();
        $this->assertSame('active', $account->status);
        $this->assertSame('support@example.com', $account->meta_json['email']);
        Queue::assertPushedOn('default', SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => ! $job->manual);
    }

    public function test_manual_and_scheduled_mailbox_syncs_have_distinct_unique_keys(): void
    {
        $scheduled = new SyncEmailAccountJob(42);
        $manual = new SyncEmailAccountJob(42, true);

        $this->assertSame('42:scheduled', $scheduled->uniqueId());
        $this->assertSame('42:manual', $manual->uniqueId());
        $this->assertCount(1, $scheduled->middleware());
        $this->assertCount(1, $manual->middleware());
    }

    public function test_failed_generic_mailbox_test_does_not_persist_or_replace_credentials(): void
    {
        $context = $this->createWorkspaceContext();
        $existing = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'business_account_id' => 'support@example.com',
            'display_name' => 'Working mailbox',
            'status' => 'active',
            'credentials' => ['username' => 'support@example.com', 'password' => 'working-password'],
            'meta_json' => ['email' => 'support@example.com'],
        ]);
        $client = Mockery::mock(GenericMailboxClient::class);
        $client->shouldReceive('verify')->once()->andThrow(new RuntimeException('SMTP connection failed: authentication failed'));
        $this->app->instance(GenericMailboxClient::class, $client);

        $this->actingAs($context['user'])->from(route('client.inbox.email.index'))->post(route('client.inbox.email.generic.store'), [
            'email' => 'support@example.com',
            'display_name' => 'Broken replacement',
            'imap_host' => 'mail.example.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'mail.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'username' => 'support@example.com',
            'password' => 'wrong-password',
            'verify_tls' => true,
        ])->assertRedirect(route('client.inbox.email.index'))
            ->assertSessionHasErrors(['connection']);

        $existing->refresh();
        $this->assertSame('active', $existing->status);
        $this->assertSame('Working mailbox', $existing->display_name);
        $this->assertSame('working-password', $existing->credentials['password']);
        $this->assertSame(1, ChannelAccount::where('provider', 'imap_smtp')->count());
    }

    public function test_multiple_generic_mailboxes_are_kept_and_exposed_to_the_master_inbox(): void
    {
        Queue::fake();
        $context = $this->createWorkspaceContext();
        $client = Mockery::mock(GenericMailboxClient::class);
        $client->shouldReceive('verify')->twice()->andReturnTrue();
        $this->app->instance(GenericMailboxClient::class, $client);

        foreach (['support@example.com', 'sales@example.com'] as $email) {
            $this->actingAs($context['user'])->post(route('client.inbox.email.generic.store'), [
                'email' => $email,
                'display_name' => ucfirst(strstr($email, '@', true)),
                'imap_host' => 'mail.example.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'smtp_host' => 'mail.example.com',
                'smtp_port' => 465,
                'smtp_encryption' => 'ssl',
                'username' => $email,
                'password' => 'secret-password',
                'verify_tls' => true,
            ])->assertSessionHas('success');
        }

        $this->assertSame(2, ChannelAccount::where('workspace_id', $context['workspace']->id)
            ->where('channel', 'email')
            ->where('status', 'active')
            ->count());
        Queue::assertPushed(SyncEmailAccountJob::class, 2);

        $this->actingAs($context['user'])
            ->get(route('client.inbox.email.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailSetup')
                ->has('accounts', 2));

        $this->actingAs($context['user'])
            ->get(route('client.inbox.email-inbox'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailInbox')
                ->has('accounts', 2));
    }

    public function test_manual_generic_sync_queues_a_bounded_refresh_for_body_repairs(): void
    {
        Queue::fake();
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'business_account_id' => 'support@example.com',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['username' => 'support@example.com'],
            'meta_json' => [
                'email' => 'support@example.com',
                'last_synced_at' => now()->toIso8601String(),
                'last_sync_error' => 'Old error',
            ],
        ]);

        $this->actingAs($context['user'])
            ->post(route('client.inbox.email.sync', $account))
            ->assertRedirect()
            ->assertSessionHas('success', 'Recent mailbox refresh queued.');

        $account->refresh();
        $this->assertArrayNotHasKey('last_synced_at', $account->meta_json);
        $this->assertArrayNotHasKey('last_sync_error', $account->meta_json);
        Queue::assertPushedOn('default', SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => $job->manual);
    }

    public function test_polling_the_master_inbox_requests_a_throttled_provider_sync(): void
    {
        Queue::fake();
        Cache::flush();
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'imap_smtp',
            'business_account_id' => 'support@example.com',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['username' => 'support@example.com'],
            'meta_json' => ['email' => 'support@example.com'],
        ]);

        $route = route('client.inbox.email.poll', ['account_id' => $account->id]);

        $this->actingAs($context['user'])->getJson($route)->assertOk();
        $this->actingAs($context['user'])->getJson($route)->assertOk();

        Queue::assertPushedOn('default', SyncEmailAccountJob::class);
        Queue::assertPushed(SyncEmailAccountJob::class, 1);
        Queue::assertPushed(SyncEmailAccountJob::class, fn (SyncEmailAccountJob $job) => $job->channelAccountId === $account->id);
    }

    public function test_microsoft_authorization_and_code_exchange_use_expected_graph_contract(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_microsoft_365',
            'label' => 'Microsoft 365 Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'client-id', 'client_secret' => 'secret', 'tenant' => 'organizations'],
            'enabled' => true,
        ]);
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => Http::response(['id' => 'user-1', 'mail' => 'agent@example.com', 'displayName' => 'Agent']),
        ]);

        $client = app(MicrosoftGraphMailClient::class);
        $url = $client->authorizationUrl('state-1', 'https://example.com/callback');
        $this->assertStringContainsString('/organizations/oauth2/v2.0/authorize', $url);
        $this->assertStringContainsString('Mail.ReadWrite', urldecode($url));
        $this->assertStringContainsString('offline_access', urldecode($url));
        $this->assertStringContainsString('prompt=select_account', $url);

        $tokens = $client->exchangeCode('code-1', 'https://example.com/callback');
        $profile = $client->profile($tokens['access_token']);
        $this->assertSame('agent@example.com', $profile['mail']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/v2.0/token')
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'secret');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com/v1.0/me')
            && $request->header('Authorization')[0] === 'Bearer access');
    }

    public function test_google_authorization_and_code_exchange_use_expected_gmail_contract(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Gmail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client-id', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-user-1', 'email' => 'agent@gmail.com', 'name' => 'Agent']),
        ]);

        $client = app(GoogleGmailClient::class);
        $url = $client->authorizationUrl('state-1', 'https://example.com/callback');
        $this->assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('gmail.readonly', urldecode($url));
        $this->assertStringContainsString('gmail.send', urldecode($url));
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent%20select_account', $url);

        $tokens = $client->exchangeCode('code-1', 'https://example.com/callback');
        $profile = $client->profile($tokens['access_token']);
        $this->assertSame('agent@gmail.com', $profile['email']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'google-secret');
    }

    public function test_email_setup_routes_are_workspace_authenticated(): void
    {
        $this->get('/app/inbox/email-setup')->assertRedirect();
        $this->get('/app/inbox/email')->assertRedirect();

        $context = $this->createWorkspaceContext();

        $this->actingAs($context['user'])
            ->get('/app/inbox/email-setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailSetup')
                ->has('accounts', 0)
                ->where('googleEnabled', false)
                ->where('microsoftEnabled', false));
    }

    public function test_email_setup_page_renders_when_google_and_microsoft_are_configured(): void
    {
        $context = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Gmail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client-id', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        IntegrationConfig::create([
            'provider' => 'oauth_microsoft_365',
            'label' => 'Microsoft 365 Mail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'microsoft-client-id', 'client_secret' => 'microsoft-secret'],
            'enabled' => true,
        ]);

        $this->actingAs($context['user'])
            ->get('/app/inbox/email-setup')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailSetup')
                ->where('googleEnabled', true)
                ->where('microsoftEnabled', true));
    }

    public function test_master_email_inbox_excludes_every_non_email_channel(): void
    {
        $context = $this->createWorkspaceContext();
        $contact = Contact::create([
            'workspace_id' => $context['workspace']->id,
            'first_name' => 'Email',
            'last_name' => 'Customer',
            'email' => 'customer@example.com',
            'source' => 'email',
        ]);
        $emailAccount = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'gmail',
            'display_name' => 'Support Gmail',
            'status' => 'active',
            'meta_json' => ['email' => 'support@example.com'],
        ]);
        $webAccount = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'webchat',
            'provider' => 'webchat',
            'display_name' => 'Website Chat',
            'status' => 'active',
        ]);
        $emailConversation = Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $emailAccount->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        Message::create([
            'conversation_id' => $emailConversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'body' => 'Please help with my account.',
            'payload' => ['subject' => 'Account assistance'],
            'status' => 'received',
            'sent_at' => now()->subMinute(),
        ]);
        Message::create([
            'conversation_id' => $emailConversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'body' => 'We are looking into it.',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        Conversation::create([
            'workspace_id' => $context['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $webAccount->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->actingAs($context['user'])
            ->get(route('client.inbox.email-inbox'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailInbox')
                ->has('conversations.data', 1)
                ->where('conversations.data.0.channel_account.channel', 'email')
                ->where('conversations.data.0.latest_inbound_message.payload.subject', 'Account assistance')
                ->has('accounts', 1)
                ->where('counts.all', 1));

        $emailConversation->update(['status' => 'resolved']);
        $this->actingAs($context['user'])
            ->get(route('client.inbox.email-inbox', ['folder' => 'resolved']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inbox/EmailInbox')
                ->where('filters.folder', 'resolved')
                ->has('conversations.data', 1)
                ->where('selectedConversation', null));
    }

    public function test_mailbox_sync_creates_one_workspace_scoped_email_conversation_and_deduplicates(): void
    {
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'microsoft_365',
            'display_name' => 'Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'token'],
            'meta_json' => ['email' => 'support@example.com'],
        ]);
        $item = [
            'id' => 'graph-message-1',
            'internetMessageId' => '<message@example.com>',
            'conversationId' => 'thread-1',
            'subject' => 'Need help',
            'from' => ['emailAddress' => ['address' => 'customer@example.com', 'name' => 'Customer One']],
            'receivedDateTime' => now()->toIso8601String(),
            'body' => ['content' => '<p>Hello support</p>'],
        ];
        $microsoft = Mockery::mock(MicrosoftGraphMailClient::class);
        $microsoft->shouldReceive('syncInbox')->twice()->withArgs(fn ($value) => $value->is($account))->andReturn([$item]);
        $google = Mockery::mock(GoogleGmailClient::class);
        $generic = Mockery::mock(GenericMailboxClient::class);

        $job = new SyncEmailAccountJob($account->id);
        $job->handle($microsoft, $google, $generic);
        Message::firstOrFail()->update(['body' => '--raw-boundary Content-Transfer-Encoding: quoted-printable']);
        $job->handle($microsoft, $google, $generic);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $message = Message::first();
        $this->assertSame('email', $message->channel);
        $this->assertSame('Hello support', $message->body);
        $this->assertSame('customer@example.com', $message->conversation->contact->email);
        $this->assertSame($context['workspace']->id, $message->conversation->workspace_id);
    }

    public function test_gmail_sync_creates_an_email_conversation(): void
    {
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'gmail',
            'display_name' => 'Gmail Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
            'meta_json' => ['email' => 'support@gmail.com'],
        ]);
        $googleItem = [
            'id' => 'gmail:message-1',
            'internetMessageId' => 'message@gmail.com',
            'conversationId' => 'gmail-thread-1',
            'subject' => 'Gmail help',
            'from' => ['emailAddress' => ['address' => 'customer@gmail.com', 'name' => 'Gmail Customer']],
            'receivedDateTime' => now()->toIso8601String(),
            'body' => ['content' => 'Hello from Gmail'],
        ];
        $microsoft = Mockery::mock(MicrosoftGraphMailClient::class);
        $google = Mockery::mock(GoogleGmailClient::class);
        $google->shouldReceive('syncInbox')->once()->withArgs(fn ($value) => $value->is($account))->andReturn([$googleItem]);
        $generic = Mockery::mock(GenericMailboxClient::class);

        (new SyncEmailAccountJob($account->id))->handle($microsoft, $google, $generic);

        $message = Message::firstOrFail();
        $this->assertSame('Hello from Gmail', $message->body);
        $this->assertSame('gmail-thread-1', $message->payload['provider_thread_id']);
        $this->assertSame('customer@gmail.com', $message->conversation->contact->email);
    }

    public function test_gmail_client_normalises_inbox_messages_and_persists_its_history_cursor(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Gmail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client-id', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'gmail',
            'display_name' => 'Gmail Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
            'meta_json' => ['email' => 'support@gmail.com'],
        ]);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'message-1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/message-1*' => Http::response([
                'id' => 'message-1',
                'threadId' => 'thread-1',
                'internalDate' => (string) (now()->timestamp * 1000),
                'labelIds' => ['INBOX', 'UNREAD'],
                'snippet' => 'Hello preview',
                'payload' => [
                    'mimeType' => 'text/plain',
                    'headers' => [
                        ['name' => 'From', 'value' => 'Customer One <customer@example.com>'],
                        ['name' => 'Subject', 'value' => 'Need help'],
                        ['name' => 'Message-ID', 'value' => '<message@example.com>'],
                    ],
                    'body' => ['data' => rtrim(strtr(base64_encode('Hello Gmail support'), '+/', '-_'), '=')],
                ],
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'support@gmail.com', 'historyId' => '98765']),
        ]);

        $items = app(GoogleGmailClient::class)->syncInbox($account);

        $this->assertCount(1, $items);
        $this->assertSame('gmail:message-1', $items[0]['id']);
        $this->assertSame('customer@example.com', data_get($items[0], 'from.emailAddress.address'));
        $this->assertSame('Hello Gmail support', data_get($items[0], 'body.content'));
        $this->assertSame('98765', $account->refresh()->meta_json['gmail_history_id']);
    }

    public function test_gmail_history_cursor_does_not_advance_when_a_message_download_fails(): void
    {
        IntegrationConfig::create([
            'provider' => 'oauth_google_mail',
            'label' => 'Google Gmail OAuth',
            'mode' => 'live',
            'credentials' => ['client_id' => 'google-client-id', 'client_secret' => 'google-secret'],
            'enabled' => true,
        ]);
        $context = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $context['workspace']->id,
            'channel' => 'email',
            'provider' => 'gmail',
            'display_name' => 'Gmail Support',
            'status' => 'active',
            'credentials' => ['access_token' => 'google-access', 'refresh_token' => 'google-refresh', 'expires_at' => now()->addHour()->toIso8601String()],
            'meta_json' => ['email' => 'support@gmail.com', 'gmail_history_id' => '100'],
        ]);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response([
                'history' => [['messagesAdded' => [['message' => ['id' => 'message-2']]]]],
                'historyId' => '200',
            ]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/message-2*' => Http::response(['error' => ['message' => 'Temporary failure']], 503),
        ]);

        try {
            app(GoogleGmailClient::class)->syncInbox($account);
            $this->fail('A failed Gmail message download should abort this sync page.');
        } catch (RuntimeException $e) {
            $this->assertSame('Gmail message download failed: Temporary failure', $e->getMessage());
        }

        $this->assertSame('100', $account->refresh()->meta_json['gmail_history_id']);
    }
}
