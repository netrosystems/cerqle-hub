<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PlanController;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspacePlanLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_limit_schema_contains_every_limit_shown_by_the_admin_form(): void
    {
        $this->assertSame([
            'users',
            'workspaces',
            'storage',
            'whatsapp_accounts',
            'whatsapp_templates',
            'whatsapp_messages_per_month',
            'campaigns_per_month',
            'sms_per_month',
            'emails_per_month',
            'inbox_agents',
            'ai_tokens_per_month',
            'knowledge_bases',
            'chatbots',
            'social_accounts',
            'social_posts_per_month',
            'automations',
        ], array_keys(PlanController::defaultLimits()));
    }

    public function test_admin_plan_creation_persists_workspace_and_other_form_limits(): void
    {
        Currency::create([
            'code' => 'USD',
            'symbol' => '$',
            'decimals' => 2,
            'exchange_rate' => 1,
            'is_default' => true,
            'enabled' => true,
        ]);
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')->post(route('admin.plans.store'), [
            'name' => 'Workspace Limited',
            'slug' => 'workspace-limited',
            'currency_code' => 'USD',
            'monthly_price_cents' => 2500,
            'limits' => [
                'workspaces' => 2,
                'automations' => 25,
                'social_accounts' => null,
            ],
            'enabled' => true,
            'featured' => false,
            'popular' => false,
            'white_label_enabled' => false,
        ])->assertRedirect(route('admin.plans.index'));

        $plan = Plan::where('slug', 'workspace-limited')->sole();
        $this->assertSame(2, $plan->limitValue('workspaces'));
        $this->assertSame(25, $plan->limitValue('automations'));
        $this->assertNull($plan->limitValue('social_accounts'));
    }

    public function test_client_can_create_up_to_its_organization_workspace_limit(): void
    {
        $ctx = $this->contextWithLimit(2);

        $this->actingAs($ctx['user'])
            ->post(route('client.workspaces.store'), ['name' => 'Second workspace'])
            ->assertRedirect(route('client.dashboard'));

        $this->assertSame(2, Workspace::where('client_id', $ctx['client']->id)->count());
        $this->assertDatabaseHas('workspaces', [
            'client_id' => $ctx['client']->id,
            'owner_id' => $ctx['user']->id,
            'name' => 'Second workspace',
        ]);
    }

    public function test_client_cannot_create_more_than_its_organization_workspace_limit(): void
    {
        $ctx = $this->contextWithLimit(2);
        $this->makeWorkspace($ctx['user'], 'Second workspace');

        $this->actingAs($ctx['user'])
            ->from(route('client.workspaces.index'))
            ->post(route('client.workspaces.store'), ['name' => 'Blocked third workspace'])
            ->assertRedirect(route('client.workspaces.index'))
            ->assertSessionHasErrors('name');

        $this->assertSame(2, Workspace::where('client_id', $ctx['client']->id)->count());
        $this->assertDatabaseMissing('workspaces', ['name' => 'Blocked third workspace']);
    }

    public function test_workspace_limit_counts_all_client_workspaces_not_only_the_users_memberships(): void
    {
        $ctx = $this->contextWithLimit(2);
        $otherOwner = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $ctx['client']->id,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->makeWorkspace($otherOwner, 'Owned by another member');

        $this->assertCount(1, $ctx['user']->accessibleWorkspaces());

        $this->actingAs($ctx['user'])
            ->post(route('client.workspaces.store'), ['name' => 'Must not bypass organization limit'])
            ->assertSessionHasErrors('name');

        $this->assertSame(2, Workspace::where('client_id', $ctx['client']->id)->count());
    }

    public function test_null_or_missing_workspace_limit_is_unlimited_for_existing_plans(): void
    {
        foreach ([['workspaces' => null], ['users' => 5]] as $limits) {
            $ctx = $this->contextWithLimits($limits);

            $this->actingAs($ctx['user'])
                ->post(route('client.workspaces.store'), ['name' => 'Additional workspace'])
                ->assertRedirect(route('client.dashboard'));

            $this->assertSame(2, Workspace::where('client_id', $ctx['client']->id)->count());
        }
    }

    public function test_zero_limit_and_plan_downgrade_block_new_workspaces_without_deleting_existing_ones(): void
    {
        $ctx = $this->contextWithLimit(0);

        $this->actingAs($ctx['user'])
            ->post(route('client.workspaces.store'), ['name' => 'Blocked workspace'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Workspace::where('client_id', $ctx['client']->id)->count());
        $this->assertDatabaseHas('workspaces', ['id' => $ctx['workspace']->id]);
    }

    public function test_whitespace_only_workspace_name_is_rejected_without_consuming_capacity(): void
    {
        $ctx = $this->contextWithLimit(2);

        $this->actingAs($ctx['user'])
            ->post(route('client.workspaces.store'), ['name' => '   '])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Workspace::where('client_id', $ctx['client']->id)->count());
    }

    public function test_workspace_page_exposes_plan_usage_and_creation_state(): void
    {
        $ctx = $this->contextWithLimit(1);

        $this->actingAs($ctx['user'])
            ->get(route('client.workspaces.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('client/Workspaces/Index')
                ->where('workspaceUsage.limit', 1)
                ->where('workspaceUsage.count', 1)
                ->where('workspaceUsage.remaining', 0)
                ->where('workspaceUsage.can_create', false));
    }

    /** @return array{user:User,workspace:Workspace,client:Client} */
    private function contextWithLimit(?int $limit): array
    {
        return $this->contextWithLimits(['workspaces' => $limit]);
    }

    /** @param array<string, int|null> $limits */
    private function contextWithLimits(array $limits): array
    {
        $ctx = $this->createWorkspaceContext();
        $plan = Plan::factory()->create(['limits' => $limits]);
        $this->attachPlanToClient($ctx['client'], $plan);

        return $ctx;
    }

    private function makeWorkspace(User $owner, string $name): Workspace
    {
        $workspace = Workspace::create([
            'client_id' => $owner->client_id,
            'owner_id' => $owner->id,
            'name' => $name,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }
}
