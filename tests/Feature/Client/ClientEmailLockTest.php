<?php

namespace Tests\Feature\Client;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientEmailLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_settings_cannot_change_the_organization_email(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext([
            'email' => 'locked@example.com',
        ]);

        $this->actingAs($user)
            ->put(route('client.settings.update'), [
                'client_name' => 'Updated organization',
                'client_email' => 'attacker@example.com',
                'client_phone_country' => 'US',
                'client_phone' => '(415) 555-2671',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('client.settings.index'));

        $client->refresh();
        $this->assertSame('locked@example.com', $client->email);
        $this->assertSame('Updated organization', $client->name);
        $this->assertSame('+14155552671', $client->phone);
    }

    public function test_client_administrator_cannot_change_a_team_member_email(): void
    {
        ['client' => $client, 'user' => $administrator, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $member = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'email' => 'locked-member@example.com',
        ]);
        $workspace->members()->attach($member->id, ['role' => 'staff']);

        $this->actingAs($administrator)
            ->from(route('client.team.index'))
            ->put(route('client.team.update', $member), [
                'name' => $member->name,
                'email' => 'changed-member@example.com',
                'status' => User::STATUS_ACTIVE,
                'workspace_assignments' => [[
                    'workspace_id' => $workspace->id,
                    'role' => 'staff',
                ]],
            ])
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('client.team.index'));

        $this->assertSame('locked-member@example.com', $member->refresh()->email);
    }

    public function test_super_admin_can_change_client_and_client_user_emails(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext([
            'email' => 'locked@example.com',
        ]);
        $admin = $this->adminWithClientUpdatePermission(true);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.clients.update', $client), $this->clientPayload($client, 'changed@example.com'))
            ->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.clients.users.update', [$client, $user]), $this->userPayload($user, 'member-changed@example.com'))
            ->assertRedirect();

        $this->assertSame('changed@example.com', $client->refresh()->email);
        $this->assertSame('member-changed@example.com', $user->refresh()->email);
    }

    public function test_non_super_admin_cannot_change_client_or_client_user_emails(): void
    {
        ['client' => $client, 'user' => $user] = $this->createWorkspaceContext([
            'email' => 'locked@example.com',
        ]);
        $admin = $this->adminWithClientUpdatePermission(false);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.clients.update', $client), $this->clientPayload($client, 'blocked@example.com'))
            ->assertForbidden();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.clients.users.update', [$client, $user]), $this->userPayload($user, 'member-blocked@example.com'))
            ->assertForbidden();

        $this->assertSame('locked@example.com', $client->refresh()->email);
        $this->assertNotSame('member-blocked@example.com', $user->refresh()->email);
    }

    private function adminWithClientUpdatePermission(bool $superAdmin): AdminUser
    {
        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create([
            'key' => $superAdmin ? Role::KEY_SUPER_ADMIN : Role::KEY_SUPPORT,
            'name' => $superAdmin ? 'Super Admin' : 'Support',
        ]);
        $permission = Permission::create([
            'key' => 'update_clients',
            'name' => 'Update Clients',
            'category' => 'Clients',
        ]);
        $role->permissions()->attach($permission);
        $admin->roles()->attach($role);

        return $admin;
    }

    private function clientPayload($client, string $email): array
    {
        return [
            'name' => $client->name,
            'email' => $email,
            'phone' => $client->phone,
            'address' => $client->address,
            'status' => $client->status,
        ];
    }

    private function userPayload($user, string $email): array
    {
        return [
            'name' => $user->name,
            'email' => $email,
            'password' => null,
            'password_confirmation' => null,
            'client_role' => $user->client_role,
            'status' => $user->status,
        ];
    }
}
