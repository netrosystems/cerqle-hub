<?php

namespace Tests\Feature\Campaign;

use App\Events\CampaignCompleted;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\FinalizeCampaignJob;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Jobs\PrepareSmsCampaignAudienceJob;
use App\Modules\Broadcasting\Jobs\PumpSmsCampaignJob;
use App\Modules\Broadcasting\Jobs\SendSmsCampaignMessageJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\CampaignStep;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Services\CampaignStepService;
use App\Modules\Broadcasting\Services\Sms\SmsDispatchRateLimiter;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Broadcasting\Services\SmsCampaignCapacityService;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SafeSmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shared_provider_credentials_share_one_rate_limit(): void
    {
        $credentials = $this->credentials();
        [, $firstWorkspace] = $this->workspaceWithProvider($credentials);
        [, $secondWorkspace] = $this->workspaceWithProvider($credentials);
        $providerKey = SmsDriverManager::providerKey('alaris', $credentials);

        $limiter = app(SmsDispatchRateLimiter::class);
        $first = $limiter->reserve($providerKey, 5);
        $second = $limiter->reserve($providerKey, 5);

        $this->assertTrue($first->reserved);
        $this->assertTrue($second->reserved);
        $this->assertLessThan(100_000, $first->waitMicroseconds);
        $this->assertGreaterThanOrEqual(150_000, $second->waitMicroseconds);
        $this->assertSame(
            SmsDriverManager::resolveForWorkspace($firstWorkspace->id)->providerKey,
            SmsDriverManager::resolveForWorkspace($secondWorkspace->id)->providerKey,
        );
    }

    #[Test]
    public function a_large_campaign_blocks_other_campaigns_using_the_same_provider_account(): void
    {
        Queue::fake();
        $credentials = $this->credentials();
        [, $firstWorkspace] = $this->workspaceWithProvider($credentials);
        [, $secondWorkspace] = $this->workspaceWithProvider($credentials);
        $providerKey = SmsDriverManager::providerKey('alaris', $credentials);
        $capacity = app(SmsCampaignCapacityService::class);

        $large = Campaign::factory()->create([
            'workspace_id' => $firstWorkspace->id,
            'channel' => 'sms',
            'status' => 'queued',
        ]);
        $small = Campaign::factory()->create([
            'workspace_id' => $secondWorkspace->id,
            'channel' => 'sms',
            'status' => 'queued',
        ]);

        $this->assertTrue($capacity->admit($large, $providerKey, 10_000));
        $this->assertFalse($capacity->admit($small, $providerKey, 5));
        $this->assertSame('waiting_capacity', $small->fresh()->status);

        $large->update(['status' => 'completed']);
        $capacity->release($large->fresh());

        Queue::assertPushed(
            LaunchCampaignJob::class,
            fn ($job) => $job->campaignId === $small->id,
        );
    }

    #[Test]
    public function a_new_large_campaign_waits_for_existing_small_campaigns(): void
    {
        $credentials = $this->credentials();
        [, $workspace] = $this->workspaceWithProvider($credentials);
        $providerKey = SmsDriverManager::providerKey('alaris', $credentials);
        $capacity = app(SmsCampaignCapacityService::class);

        $small = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'sending',
            'provider_key' => $providerKey,
        ]);
        $large = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'queued',
        ]);

        $this->assertFalse($capacity->admit($large, $providerKey, 10_000));
        $this->assertSame('waiting_capacity', $large->fresh()->status);
        $this->assertSame('sending', $small->fresh()->status);
    }

    #[Test]
    public function workers_do_not_block_on_far_future_rate_slots(): void
    {
        [, $workspace] = $this->workspaceWithProvider();
        $providerKey = SmsDriverManager::resolveForWorkspace($workspace->id)->providerKey;
        $limiter = app(SmsDispatchRateLimiter::class);

        $limiter->reserveMany(['provider:'.$providerKey => 1], 2_000_000);
        $deferred = $limiter->reserveMany(['provider:'.$providerKey => 1], 100_000);

        $this->assertFalse($deferred->reserved);
        $this->assertGreaterThan(100_000, $deferred->waitMicroseconds);
    }

    #[Test]
    public function preparation_is_chunked_idempotent_and_assigns_delivery_steps(): void
    {
        Queue::fake();
        config(['broadcasting.sms.audience_chunk_size' => 100]);
        [, $workspace] = $this->workspaceWithProvider();
        Contact::factory()->count(101)->create(['workspace_id' => $workspace->id]);

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'preparing',
            'audience_type' => 'contact_list',
            'audience_cutoff_id' => Contact::where('workspace_id', $workspace->id)->max('id'),
            'estimated_recipients' => 101,
        ]);
        app(CampaignStepService::class)->ensure($campaign);

        app()->call([new PrepareSmsCampaignAudienceJob($campaign->id), 'handle']);
        $campaign->refresh();
        $this->assertSame(100, $campaign->prepared_recipients);
        $this->assertNull($campaign->audience_prepared_at);
        $this->assertSame(100, $campaign->recipients()->count());

        // A restart continues from the saved cursor and cannot duplicate rows.
        app()->call([new PrepareSmsCampaignAudienceJob($campaign->id), 'handle']);
        app()->call([new PrepareSmsCampaignAudienceJob($campaign->id), 'handle']);
        $campaign->refresh();

        $this->assertSame(101, $campaign->recipients()->count());
        $this->assertSame('sending', $campaign->status);
        $this->assertNotNull($campaign->audience_prepared_at);
        $this->assertSame(101, $campaign->recipients()->distinct('contact_id')->count('contact_id'));
        $this->assertSame(2, $campaign->recipients()->distinct()->count('campaign_step_id'));
        Queue::assertPushed(PumpSmsCampaignJob::class);
    }

    #[Test]
    public function a_partially_prepared_campaign_resumes_preparation_instead_of_sending(): void
    {
        Queue::fake();
        config(['broadcasting.sms.audience_chunk_size' => 100]);
        [, $workspace] = $this->workspaceWithProvider();
        Contact::factory()->count(101)->create(['workspace_id' => $workspace->id]);
        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'preparing',
            'audience_type' => 'contact_list',
            'audience_cutoff_id' => Contact::where('workspace_id', $workspace->id)->max('id'),
        ]);
        app(CampaignStepService::class)->ensure($campaign);
        app()->call([new PrepareSmsCampaignAudienceJob($campaign->id), 'handle']);

        $campaign->refresh()->update(['status' => 'queued']);
        Queue::fake();
        (new LaunchCampaignJob($campaign->id))->handle();

        $campaign->refresh();
        $this->assertSame('preparing', $campaign->status);
        $this->assertSame(100, $campaign->prepared_recipients);
        $this->assertNull($campaign->audience_prepared_at);
        Queue::assertPushed(PrepareSmsCampaignAudienceJob::class);
        Queue::assertNotPushed(PumpSmsCampaignJob::class);
    }

    #[Test]
    public function the_pump_never_claims_beyond_its_rolling_buffer(): void
    {
        Queue::fake();
        config(['broadcasting.sms.dispatch_buffer' => 25]);
        [$campaign, $step, $inFlight] = $this->sendingCampaign(25);
        $contacts = Contact::factory()->count(10)->create([
            'workspace_id' => $campaign->workspace_id,
            'opt_in_sms' => true,
        ]);
        foreach ($contacts as $contact) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'campaign_step_id' => $step->id,
                'contact_id' => $contact->id,
                'status' => 'queued',
                'idempotency_key' => (string) Str::uuid(),
            ]);
        }

        (new PumpSmsCampaignJob($campaign->id))->handle();

        $this->assertSame(25, CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['dispatching', 'sending'])
            ->count());
        $this->assertSame(10, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->count());
        Queue::assertNotPushed(SendSmsCampaignMessageJob::class);
        $this->assertCount(25, $inFlight);
    }

    #[Test]
    public function a_permanent_recipient_failure_does_not_stop_the_next_contact(): void
    {
        Http::fakeSequence()
            ->push(['error' => 'NO ROUTES'], 400)
            ->push(['message_id' => 'accepted-2'], 200);

        [$campaign, $step, $recipients] = $this->sendingCampaign(2);
        foreach ($recipients as $recipient) {
            app()->call([new SendSmsCampaignMessageJob($recipient->id), 'handle']);
        }

        $this->assertSame('failed', $recipients[0]->fresh()->status);
        $this->assertSame('no_route', $recipients[0]->fresh()->failure_class);
        $this->assertSame('sent', $recipients[1]->fresh()->status);
        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertSame('active', $step->fresh()->status);
    }

    #[Test]
    public function transient_failures_are_deferred_without_blocking_the_worker(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Service unavailable'], 503)]);
        [, , $recipients] = $this->sendingCampaign(1);

        app()->call([new SendSmsCampaignMessageJob($recipients[0]->id), 'handle']);
        $recipient = $recipients[0]->fresh();

        $this->assertSame('retrying', $recipient->status);
        $this->assertSame('temporary', $recipient->failure_class);
        $this->assertNotNull($recipient->next_attempt_at);
        $this->assertSame(1, $recipient->attempts);
    }

    #[Test]
    public function repeated_authentication_failures_pause_the_campaign_safely(): void
    {
        Http::fake(['*' => Http::response('not authorized (check login and password)', 401)]);
        [$campaign, , $recipients] = $this->sendingCampaign(3);

        foreach ($recipients as $recipient) {
            app()->call([new SendSmsCampaignMessageJob($recipient->id), 'handle']);
        }

        $this->assertSame('safety_paused', $campaign->fresh()->status);
        $this->assertStringContainsString('provider', strtolower((string) $campaign->fresh()->pause_reason));
        $this->assertSame(3, CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'retrying')->count());
    }

    #[Test]
    public function mixed_recipient_results_finalize_without_losing_failure_visibility(): void
    {
        Event::fake([CampaignCompleted::class]);
        [$campaign, $step, $recipients] = $this->sendingCampaign(2);
        $recipients[0]->update(['status' => 'sent', 'sent_at' => now(), 'claimed_at' => null]);
        $recipients[1]->update([
            'status' => 'failed',
            'failure_class' => 'recipient',
            'failed_reason' => 'NO ROUTES',
            'claimed_at' => null,
        ]);

        app()->call([new FinalizeCampaignJob($campaign->id), 'handle']);

        $campaign->refresh();
        $this->assertSame('completed_with_failures', $campaign->status);
        $this->assertSame(1, $campaign->totals_json['sent']);
        $this->assertSame(1, $campaign->totals_json['failed']);
        $this->assertSame('completed', $step->fresh()->status);
        Event::assertDispatched(CampaignCompleted::class);
    }

    #[Test]
    public function the_default_plan_keeps_a_million_contact_campaign_bounded(): void
    {
        [, $workspace] = $this->workspaceWithProvider();
        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'draft',
        ]);
        $service = app(CampaignStepService::class);
        $service->ensure($campaign);

        $campaign->load('steps');
        $this->assertCount(2, $campaign->steps);
        $this->assertSame(100, $campaign->steps[0]->recipient_limit);
        $this->assertSame(600, $campaign->steps[1]->delay_after_previous_seconds);
        $this->assertSame($campaign->steps[0]->id, $service->forOrdinal($campaign, 100)->id);
        $this->assertSame($campaign->steps[1]->id, $service->forOrdinal($campaign, 1_000_000)->id);
        $this->assertLessThanOrEqual(5, $campaign->steps->max('rate_per_second'));
    }

    /**
     * @return array{0: Campaign, 1: CampaignStep, 2: array<int, CampaignRecipient>}
     */
    private function sendingCampaign(int $recipientCount): array
    {
        [, $workspace] = $this->workspaceWithProvider();
        $resolved = SmsDriverManager::resolveForWorkspace($workspace->id);
        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'status' => 'sending',
            'audience_type' => 'contact_list',
            'provider_key' => $resolved->providerKey,
            'payload_json' => ['body' => 'Hello {{first_name}}'],
            'audience_prepared_at' => now(),
        ]);
        $step = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'position' => 1,
            'name' => 'Delivery',
            'recipient_limit' => null,
            'delay_after_previous_seconds' => 0,
            'rate_per_second' => 5,
            'status' => 'active',
            'started_at' => now(),
        ]);
        $contacts = Contact::factory()->count($recipientCount)->create([
            'workspace_id' => $workspace->id,
            'opt_in_sms' => true,
        ]);

        $recipients = $contacts->map(fn ($contact) => CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'campaign_step_id' => $step->id,
            'contact_id' => $contact->id,
            'status' => 'dispatching',
            'claimed_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
        ]))->all();

        app(SmsCampaignCapacityService::class)->admit($campaign, $resolved->providerKey, $recipientCount);
        $campaign->update(['status' => 'sending']);

        return [$campaign->fresh(), $step, $recipients];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceWithProvider(?array $credentials = null): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'alaris',
            'credentials' => $credentials ?? $this->credentials(),
            'default' => true,
        ]);

        return [$user, $workspace];
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'base_url' => 'https://sms.test/api',
            'username' => 'test-user',
            'password' => 'test-password',
            'sender_id' => 'CERQLE',
        ];
    }
}
