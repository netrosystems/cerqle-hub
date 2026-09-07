<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Workspace;
use App\Modules\Inbox\Services\GoogleGmailClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Shared\Models\ChannelAccount;
use App\Services\EmailAccountLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailAccountPlanLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_is_shared_across_workspaces_and_providers(): void
    {
        $ctx = $this->context(2);
        $second = Workspace::create(['owner_id' => $ctx['user']->id, 'client_id' => $ctx['client']->id, 'name' => 'Second']);
        $this->connect($ctx['workspace'], 'gmail', 'one');
        $this->connect($second, 'microsoft_365', 'two');
        $this->expectException(ValidationException::class);
        $this->connect($second, 'imap_smtp', 'three');
    }

    public function test_reconnect_at_capacity_updates_one_record_and_disconnect_releases_capacity(): void
    {
        $ctx = $this->context(1);
        $first = $this->connect($ctx['workspace'], 'gmail', 'one');
        $again = $this->connect($ctx['workspace'], 'gmail', 'one');
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ChannelAccount::count());
        $first->delete();
        $this->connect($ctx['workspace'], 'imap_smtp', 'two');
        $this->assertSame(1, ChannelAccount::count());
    }

    public function test_zero_blocks_connections(): void
    {
        $ctx = $this->context(0);
        $this->expectException(ValidationException::class);
        $this->connect($ctx['workspace'], 'gmail', 'one');
    }

    public function test_legacy_and_unlimited_plans_allow_connections(): void
    {
        foreach ([[], ['email_accounts' => null]] as $limits) {
            $ctx = $this->createWorkspaceContext();
            $this->attachPlanToClient($ctx['client'], Plan::factory()->create(['limits' => $limits]));
            $this->connect($ctx['workspace'], 'gmail', 'one');
            $this->connect($ctx['workspace'], 'gmail', 'two');
            $this->assertTrue(app(EmailAccountLimitService::class)->usage($ctx['workspace'])['unlimited']);
        }
    }

    public function test_downgrade_keeps_existing_mailboxes_and_reconnects_but_blocks_new_accounts(): void
    {
        $ctx = $this->context(2);
        $first = $this->connect($ctx['workspace'], 'gmail', 'one');
        $first->update(['status' => 'inactive']);
        $ctx['plan']->update(['limits' => ['email_accounts' => 0]]);
        $usage = app(EmailAccountLimitService::class)->usage($ctx['workspace']);
        $this->assertSame(1, $usage['used']);
        $this->assertSame(0, $usage['remaining']);
        $this->assertFalse($usage['can_connect']);
        $this->assertSame($first->id, $this->connect($ctx['workspace'], 'gmail', 'one')->id);
        $this->expectException(ValidationException::class);
        $this->connect($ctx['workspace'], 'gmail', 'two');
    }

    public function test_other_organizations_do_not_consume_allowance(): void
    {
        $first = $this->context(1);
        $second = $this->context(1);
        $this->connect($first['workspace'], 'gmail', 'same-provider-id');
        $this->connect($second['workspace'], 'gmail', 'same-provider-id');
        $this->assertSame(1, app(EmailAccountLimitService::class)->usage($second['workspace'])['used']);
    }

    private function context(?int $limit): array
    {
        $ctx = $this->createWorkspaceContext();
        $ctx['plan'] = Plan::factory()->create(['limits' => ['email_accounts' => $limit]]);
        $this->attachPlanToClient($ctx['client'], $ctx['plan']);

        return $ctx;
    }

    public function test_oauth_callbacks_enforce_capacity_and_do_not_queue_rejected_mailboxes(): void
    {
        Queue::fake();
        $ctx = $this->context(0);
        foreach ([
            'google' => GoogleGmailClient::class,
            'microsoft' => MicrosoftGraphMailClient::class,
        ] as $provider => $class) {
            $mock = \Mockery::mock($class);
            $mock->shouldReceive('exchangeCode')->once()->andReturn(['access_token' => 'test', 'refresh_token' => 'test']);
            $mock->shouldReceive('profile')->once()->andReturn([
                'sub' => 'test-id', 'id' => 'test-id', 'email' => 'test@example.com',
                'mail' => 'test@example.com', 'displayName' => 'Test',
            ]);
            $this->app->instance($class, $mock);
            $this->actingAs($ctx['user'])->withSession([$provider.'_mail_oauth' => [
                'state' => hash('sha256', 'test-state'), 'workspace_id' => $ctx['workspace']->id,
                'user_id' => $ctx['user']->id, 'created_at' => now()->timestamp,
            ]])->get(route('client.inbox.email.'.$provider.'.callback', ['state' => 'test-state', 'code' => 'test-code']))
                ->assertRedirect(route('client.inbox.email.index'))
                ->assertSessionHas('error', fn ($error) => str_contains($error, '0 connected email accounts'));
        }
        $this->assertSame(0, ChannelAccount::count());
        Queue::assertNothingPushed();
    }

    public function test_no_plan_blocks_new_mailboxes(): void
    {
        $ctx = $this->createWorkspaceContext();
        $this->expectException(ValidationException::class);
        $this->connect($ctx['workspace'], 'gmail', 'one');
    }

    private function connect(Workspace $workspace, string $provider, string $identity): ChannelAccount
    {
        return app(EmailAccountLimitService::class)->save([
            'workspace_id' => $workspace->id, 'channel' => 'email',
            'provider' => $provider, 'business_account_id' => $identity,
        ], ['display_name' => 'Test mailbox', 'status' => 'active']);
    }
}
