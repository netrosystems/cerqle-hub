<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Invitation;
use App\Models\Workspace;
use App\Providers\BroadcastChannelsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_grant_a_member_access_to_only_selected_workspaces(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);
        $admin = $ctx['user'];
        $first = $ctx['workspace'];
        $second = Workspace::create([
            'client_id' => $ctx['client']->id,
            'owner_id' => $admin->id,
            'name' => 'Second workspace',
        ]);
        $second->members()->attach($admin->id, ['role' => 'owner']);

        $this->actingAs($admin)->post(route('client.team.store'), [
            'name' => 'Only First',
            'email' => 'first-only@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'client_role' => 'staff',
            'status' => 'active',
            'workspace_assignments' => [[
                'workspace_id' => $first->id,
                'role' => 'staff',
            ]],
        ])->assertRedirect(route('client.team.index'));

        $member = User::where('email', 'first-only@example.com')->sole();
        $this->assertTrue($member->canAccessWorkspace($first));
        $this->assertFalse($member->canAccessWorkspace($second));
        $this->assertSame([$first->id], $member->accessibleWorkspaces()->pluck('id')->all());
        $this->assertTrue(BroadcastChannelsServiceProvider::userCanAccessWorkspace($member, $first->id));
        $this->assertFalse(BroadcastChannelsServiceProvider::userCanAccessWorkspace($member, $second->id));
    }

    public function test_admin_can_add_and_remove_workspace_access_without_deleting_the_member(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);
        $admin = $ctx['user'];
        $first = $ctx['workspace'];
        $second = Workspace::create([
            'client_id' => $ctx['client']->id,
            'owner_id' => $admin->id,
            'name' => 'Second workspace',
        ]);
        $second->members()->attach($admin->id, ['role' => 'owner']);
        $member = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $ctx['client']->id,
            'client_role' => 'staff',
            'workspace_id' => $first->id,
        ]);

        $payload = [
            'name' => $member->name,
            'email' => $member->email,
            'client_role' => 'staff',
            'status' => 'active',
            'workspace_assignments' => [[
                'workspace_id' => $second->id,
                'role' => 'administrator',
            ]],
        ];
        $this->actingAs($admin)->put(route('client.team.update', $member), $payload)->assertRedirect(route('client.team.index'));

        $member->refresh();
        $this->assertFalse($member->canAccessWorkspace($first));
        $this->assertTrue($member->canAccessWorkspace($second));
        $this->assertSame('administrator', $member->workspaceRole($second));
        $this->assertSame($second->id, $member->workspace_id);
    }

    public function test_workspace_switcher_rejects_unassigned_workspace(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);
        $other = Workspace::create([
            'client_id' => $ctx['client']->id,
            'name' => 'Private workspace',
        ]);
        $staff = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $ctx['client']->id,
            'client_role' => 'staff',
            'workspace_id' => $ctx['workspace']->id,
        ]);

        $this->actingAs($staff)
            ->post(route('client.workspaces.switch'), ['workspace_id' => $other->id])
            ->assertForbidden();
    }

    public function test_workspace_owner_cannot_be_removed_by_a_team_assignment_update(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);
        $admin = $ctx['user'];
        $ownedWorkspace = $ctx['workspace'];
        $otherWorkspace = Workspace::create([
            'client_id' => $ctx['client']->id,
            'owner_id' => $admin->id,
            'name' => 'Second workspace',
        ]);
        $otherWorkspace->members()->attach($admin->id, ['role' => 'owner']);

        $this->actingAs($admin)->put(route('client.team.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'workspace_assignments' => [[
                'workspace_id' => $otherWorkspace->id,
                'role' => 'administrator',
            ]],
        ])->assertRedirect(route('client.team.index'));

        $admin->refresh();
        $this->assertTrue($admin->canAccessWorkspace($ownedWorkspace));
        $this->assertTrue($admin->canAccessWorkspace($otherWorkspace));
    }

    public function test_accepting_an_invitation_assigns_only_its_selected_workspaces(): void
    {
        $ctx = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);
        $first = $ctx['workspace'];
        $second = Workspace::create([
            'client_id' => $ctx['client']->id,
            'owner_id' => $ctx['user']->id,
            'name' => 'Not invited',
        ]);
        $second->members()->attach($ctx['user']->id, ['role' => 'owner']);
        $invitation = Invitation::create([
            'client_id' => $ctx['client']->id,
            'email' => 'accepted@example.com',
            'client_role' => 'staff',
            'token' => str_repeat('x', 64),
            'invited_by' => $ctx['user']->id,
            'expires_at' => now()->addDay(),
        ]);
        $invitation->workspaces()->attach($first->id, ['role' => 'administrator']);

        $this->post(route('auth.invitations.accept', $invitation->token), [
            'name' => 'Accepted Member',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('client.dashboard'));

        $member = User::where('email', 'accepted@example.com')->sole();
        $this->assertTrue($member->canAccessWorkspace($first));
        $this->assertFalse($member->canAccessWorkspace($second));
        $this->assertSame('administrator', $member->workspaceRole($first));
    }
}
