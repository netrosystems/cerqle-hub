<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionStorageUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_api_reports_finite_storage_usage(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $plan = Plan::factory()->create(['limits' => ['storage' => 10]]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'gateway' => 'manual',
            'starts_at' => now(),
        ]);
        Media::factory()->create([
            'mediable_type' => User::class,
            'mediable_id' => $user->id,
            'size_bytes' => 2 * 1024 * 1024,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.storage.used_bytes', 2 * 1024 * 1024)
            ->assertJsonPath('data.storage.quota_bytes', 10 * 1024 * 1024)
            ->assertJsonPath('data.storage.remaining_bytes', 8 * 1024 * 1024)
            ->assertJsonPath('data.storage.percent_used', 20)
            ->assertJsonPath('data.storage.unlimited', false)
            ->assertJsonPath('data.storage.is_full', false);
    }

    public function test_subscription_api_reports_unlimited_storage(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $plan = Plan::factory()->create(['limits' => ['storage' => null]]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'gateway' => 'manual',
            'starts_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/subscription')
            ->assertOk()
            ->assertJsonPath('data.storage.quota_bytes', null)
            ->assertJsonPath('data.storage.remaining_bytes', null)
            ->assertJsonPath('data.storage.unlimited', true)
            ->assertJsonPath('data.storage.is_full', false);
    }
}
