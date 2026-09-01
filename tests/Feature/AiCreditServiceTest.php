<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Services\AiCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiCreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private function context(int $allowance = 5, bool $verified = true, int $price = 2000): array
    {
        $plan = Plan::factory()->create([
            'monthly_price_cents' => $price,
            'price_cents' => $price,
            'limits' => ['ai_credits_per_month' => $allowance],
        ]);
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => $verified ? now() : null]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'month',
            'starts_at' => now()->subDays(8),
            'gateway' => 'manual',
        ]);

        return [$user->fresh(), $workspace, $plan];
    }

    #[Test]
    public function reservations_are_finalized_once_and_idempotent(): void
    {
        config(['ai.credits.enforced' => true]);
        [$user, $workspace] = $this->context();
        $service = app(AiCreditService::class);

        $usage = $service->reserve($workspace->id, 'email_compose', 'same-request', $user);
        $this->assertSame(2, $service->usageForWorkspace($workspace)['reserved']);
        $service->complete($usage, ['content' => 'Done'], 10, 5, 'gpt-5-mini');
        $service->complete($usage, ['content' => 'Done'], 10, 5, 'gpt-5-mini');

        $retry = $service->reserve($workspace->id, 'email_compose', 'same-request', $user);
        $this->assertSame($usage->id, $retry->id);
        $this->assertSame(2, $service->usageForWorkspace($workspace)['used']);
    }

    #[Test]
    public function workspaces_share_the_owner_subscription_pool(): void
    {
        config(['ai.credits.enforced' => true]);
        [$user, $workspace] = $this->context();
        $second = Workspace::factory()->create(['owner_id' => $user->id]);
        $service = app(AiCreditService::class);

        $first = $service->reserve($workspace->id, 'social_plan_generate', 'first', $user);
        $service->complete($first, ['content' => 'Plan'], 1, 1, 'gpt-5-mini');

        $this->assertSame(5, $service->usageForWorkspace($second)['used']);
        $this->expectException(AiCreditsExhaustedException::class);
        $service->reserve($second->id, 'rag_reply', 'blocked', $user);
    }

    #[Test]
    public function failed_action_refunds_reserved_credits(): void
    {
        config(['ai.credits.enforced' => true]);
        [$user, $workspace] = $this->context();
        $service = app(AiCreditService::class);
        $usage = $service->reserve($workspace->id, 'email_compose', 'failure', $user);

        $service->refund($usage);

        $this->assertSame(0, $service->usageForWorkspace($workspace)['used']);
        $this->assertSame(0, $service->usageForWorkspace($workspace)['reserved']);

        $retry = $service->reserve($workspace->id, 'email_compose', 'failure', $user);
        $this->assertTrue($retry->wasRecentlyCreated);
        $this->assertSame(2, $service->usageForWorkspace($workspace)['reserved']);
    }

    #[Test]
    public function duplicate_in_flight_reservation_is_detectable_without_double_reserving(): void
    {
        config(['ai.credits.enforced' => true]);
        [$user, $workspace] = $this->context();
        $service = app(AiCreditService::class);
        $first = $service->reserve($workspace->id, 'email_compose', 'in-flight', $user);
        $duplicate = $service->reserve($workspace->id, 'email_compose', 'in-flight', $user);

        $this->assertSame($first->id, $duplicate->id);
        $this->assertFalse($duplicate->wasRecentlyCreated);
        $this->assertSame(2, $service->usageForWorkspace($workspace)['reserved']);
    }

    #[Test]
    public function malformed_completed_action_is_refunded(): void
    {
        config(['ai.credits.enforced' => true]);
        [$user, $workspace] = $this->context();
        $service = app(AiCreditService::class);
        $usage = $service->reserve($workspace->id, 'email_compose', 'malformed', $user);
        $service->complete($usage, ['content' => 'not usable'], 5, 2, 'gpt-5-mini');

        $service->refundCompleted($usage->id, 'malformed_response');

        $this->assertSame(0, $service->usageForWorkspace($workspace)['used']);
        $this->assertDatabaseHas('ai_credit_usages', ['id' => $usage->id, 'status' => 'refunded', 'charged_credits' => 0]);
    }

    #[Test]
    public function unverified_free_user_cannot_reserve_managed_credits(): void
    {
        config(['ai.credits.enforced' => true]);
        [$user, $workspace] = $this->context(100, false, 0);

        $this->expectException(\LogicException::class);
        app(AiCreditService::class)->reserve($workspace->id, 'rag_reply', 'free-abuse-check', $user);
    }
}
