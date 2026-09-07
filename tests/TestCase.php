<?php

namespace Tests;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientAddonSubscription;
use App\Models\ClientSubscription;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Services\AddonEntitlementService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['saas.enforce_client_subscription' => false]);
    }

    /** Exercise the real CSRF check, which Laravel normally skips under PHPUnit. */
    protected function enforceCsrfForNextRequests(): void
    {
        $this->app->instance(ValidateCsrfToken::class,
            new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
    }

    /**
     * Create an AdminUser with the SUPER_ADMIN role and all permissions,
     * so RBAC middleware passes in feature tests.
     */
    protected function createSuperAdmin(array $attrs = []): AdminUser
    {
        $admin = AdminUser::factory()->create(array_merge(['status' => AdminUser::STATUS_ACTIVE], $attrs));

        // Ensure SUPER_ADMIN role exists
        $role = Role::firstOrCreate(
            ['key' => Role::KEY_SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'Full access']
        );

        // Attach common permissions so permission middleware passes
        $permKeys = [
            'view_settings', 'manage_settings',
            'view_clients', 'manage_clients', 'create_clients', 'update_clients', 'delete_clients',
            'view_plans', 'manage_plans', 'create_plans', 'delete_plans',
            'view_subscriptions', 'manage_subscriptions',
            'view_payment_gateways', 'manage_payment_gateways',
            'view_admins', 'create_admins', 'update_admins', 'delete_admins',
            'view_admin_roles', 'manage_admin_roles',
            'view_email_settings', 'manage_email_settings',
            'manage_integrations',
            'view_currencies', 'view_languages',
        ];

        foreach ($permKeys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'general']
            );
            if (! $role->permissions->contains('key', $key)) {
                $role->permissions()->syncWithoutDetaching([$perm->id]);
            }
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);
        $admin->unsetRelation('roles');

        return $admin;
    }

    /**
     * Create a Client, User, and Workspace for feature tests.
     * Returns ['user' => User, 'workspace' => Workspace, 'client' => Client].
     */
    protected function createWorkspaceContext(array $clientAttrs = [], array $userAttrs = [], array $workspaceAttrs = []): array
    {
        $client = Client::create(array_merge([
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'status' => Client::STATUS_ACTIVE,
        ], $clientAttrs));

        $user = User::factory()->create(array_merge([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $userAttrs));

        $user->refresh();
        $workspace = $client->workspaces()->orderBy('id')->first();
        if ($workspace && $workspaceAttrs !== []) {
            $workspace->update($workspaceAttrs);
        }

        return ['user' => $user, 'workspace' => $workspace, 'client' => $client];
    }

    /** Operational fixtures opt into a real, finite subscription; no-plan tests remain unchanged. */
    protected function createSubscribedWorkspaceContext(array $clientAttrs = [], array $userAttrs = [], array $workspaceAttrs = []): array
    {
        $context = $this->createWorkspaceContext($clientAttrs, $userAttrs, $workspaceAttrs);
        $plan = Plan::factory()->create(['limits' => [
            'messaging_channels' => 20,
            'website_widgets' => 20,
            'whatsapp_chatbots' => 20,
            'social_accounts' => 20,
            'messaging_messages_per_month' => 1000,
        ]]);
        $this->attachPlanToClient($context['client'], $plan);

        return $context;
    }

    protected function grantDeveloperToolsAddon(Client|User $subject): ClientAddonSubscription
    {
        $client = $subject instanceof User ? $subject->client : $subject;
        $user = $subject instanceof User ? $subject : User::where('client_id', $client->id)->first();

        return ClientAddonSubscription::updateOrCreate(
            [
                'client_id' => $client->id,
                'addon_key' => AddonEntitlementService::DEVELOPER_TOOLS,
            ],
            [
                'purchased_by_user_id' => $user?->id,
                'status' => ClientAddonSubscription::STATUS_ACTIVE,
                'gateway' => 'manual',
                'gateway_subscription_id' => 'sub_devtools_test_'.$client->id,
                'starts_at' => now(),
                'ends_at' => null,
            ]
        );
    }

    protected function configureTestSmsProvider(int $workspaceId): void
    {
        SmsProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'twilio',
            'credentials' => [
                'account_sid' => 'AC-test',
                'auth_token' => 'test-only-token',
                'from_number' => '+15555550123',
            ],
            'default' => true,
        ]);
    }

    /**
     * Attach a Plan to a Client via an active ClientSubscription.
     */
    protected function attachPlanToClient(Client $client, Plan $plan): ClientSubscription
    {
        return ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);
    }
}
