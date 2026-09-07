<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AiRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_rate_limiter_reads_plan_without_treating_helper_as_relation(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['limits' => ['ai_runs_per_minute' => 7]]));
        $user->setAttribute('current_workspace_id', $workspace->id);
        $request = Request::create('/test-ai-rate');
        $request->setUserResolver(fn () => $user);

        $limit = RateLimiter::limiter('ai-runs')($request);

        $this->assertSame(7, $limit->maxAttempts);
        $this->assertSame((string) $workspace->id, $limit->key);
    }
}
