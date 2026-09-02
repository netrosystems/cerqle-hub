<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['saas.enforce_client_subscription' => true]);
    }

    public function test_verified_client_without_plan_is_blocked_from_operational_features(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)->get(route('client.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('client.social.posts.index'))
            ->assertRedirect(route('client.pricing'));
    }

    public function test_free_plan_must_be_explicitly_activated_and_is_shared_with_team(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();
        $staff = User::factory()->create([
            'role' => 'client',
            'client_id' => $client->id,
            'client_role' => 'staff',
            'email_verified_at' => now(),
        ]);
        $plan = Plan::factory()->create([
            'price_cents' => 0,
            'monthly_price_cents' => 0,
            'yearly_price_cents' => 0,
            'limits' => ['social_posts_per_month' => 5],
        ]);

        $this->actingAs($admin)->post(route('client.subscription.activate-free'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'month',
        ])->assertRedirect(route('client.dashboard'));

        $this->assertSame($plan->id, $staff->fresh()->effectiveSubscription()?->plan_id);
    }

    public function test_expired_plan_allows_reads_and_blocks_writes(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $plan = Plan::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'billing_cycle' => 'month',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'gateway' => 'manual',
        ]);

        $this->actingAs($user)->get(route('client.social.posts.index'))->assertOk();
        $this->actingAs($user)->post(route('client.social.ai-generate'), [])->assertRedirect(route('client.dashboard'));
    }

    public function test_queued_outbound_work_is_paused_when_subscription_is_inactive(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $plan = Plan::factory()->create();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'cancelled',
            'billing_cycle' => 'month',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'gateway' => 'manual',
        ]);
        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'status' => 'queued',
        ]);

        (new LaunchCampaignJob($campaign->id))->handle();

        $this->assertSame('safety_paused', $campaign->fresh()->status);
        $this->assertStringContainsString('subscription is inactive', $campaign->fresh()->pause_reason);
    }
}
