<?php

namespace Tests\Feature;

use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class EmailInboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

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

        $tokens = $client->exchangeCode('code-1', 'https://example.com/callback');
        $profile = $client->profile($tokens['access_token']);
        $this->assertSame('agent@example.com', $profile['mail']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth2/v2.0/token')
            && $request['grant_type'] === 'authorization_code'
            && $request['client_secret'] === 'secret');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'graph.microsoft.com/v1.0/me')
            && $request->header('Authorization')[0] === 'Bearer access');
    }

    public function test_email_setup_routes_are_workspace_authenticated(): void
    {
        $this->get('/app/inbox/email-setup')->assertRedirect();
        $this->get('/app/inbox/email')->assertRedirect();
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
        $generic = Mockery::mock(GenericMailboxClient::class);

        $job = new SyncEmailAccountJob($account->id);
        $job->handle($microsoft, $generic);
        $job->handle($microsoft, $generic);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        $message = Message::first();
        $this->assertSame('email', $message->channel);
        $this->assertSame('Hello support', $message->body);
        $this->assertSame('customer@example.com', $message->conversation->contact->email);
        $this->assertSame($context['workspace']->id, $message->conversation->workspace_id);
    }
}
