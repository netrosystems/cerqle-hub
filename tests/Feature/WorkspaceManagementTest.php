<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_can_rename_workspace(): void
    {
        $ctx = $this->createWorkspaceContext();

        $this->actingAs($ctx['user'])
            ->put(route('client.workspaces.update', $ctx['workspace']), ['name' => '  Renamed workspace  '])
            ->assertRedirect();

        $this->assertDatabaseHas('workspaces', [
            'id' => $ctx['workspace']->id,
            'name' => 'Renamed workspace',
        ]);
    }

    public function test_user_cannot_manage_a_workspace_from_another_client(): void
    {
        $ctx = $this->createWorkspaceContext();
        $foreign = $this->createWorkspaceContext();

        $this->actingAs($ctx['user'])
            ->put(route('client.workspaces.update', $foreign['workspace']), ['name' => 'Unauthorized'])
            ->assertForbidden();

        $this->actingAs($ctx['user'])
            ->delete(route('client.workspaces.destroy', $foreign['workspace']), ['confirmation' => $foreign['workspace']->name])
            ->assertForbidden();
    }

    public function test_workspace_name_must_be_typed_exactly_before_deletion(): void
    {
        $ctx = $this->contextWithSecondWorkspace();

        $this->actingAs($ctx['user'])
            ->from(route('client.workspaces.index'))
            ->delete(route('client.workspaces.destroy', $ctx['workspace']), ['confirmation' => 'wrong name'])
            ->assertRedirect(route('client.workspaces.index'))
            ->assertSessionHasErrors('confirmation');

        $this->assertDatabaseHas('workspaces', ['id' => $ctx['workspace']->id]);
    }

    public function test_only_workspace_cannot_be_deleted(): void
    {
        $ctx = $this->createWorkspaceContext();

        $this->actingAs($ctx['user'])
            ->from(route('client.workspaces.index'))
            ->delete(route('client.workspaces.destroy', $ctx['workspace']), ['confirmation' => $ctx['workspace']->name])
            ->assertRedirect(route('client.workspaces.index'))
            ->assertSessionHasErrors('workspace');

        $this->assertDatabaseHas('workspaces', ['id' => $ctx['workspace']->id]);
    }

    public function test_deleting_workspace_purges_related_data_and_selects_a_fallback(): void
    {
        $ctx = $this->contextWithSecondWorkspace();
        $workspace = $ctx['workspace'];

        $contactId = DB::table('contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'email' => 'delete-me@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $tagId = DB::table('contact_tags')->insertGetId([
            'workspace_id' => $workspace->id,
            'name' => 'Delete me',
            'color' => '#000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('contact_tag_pivot')->insert(['contact_id' => $contactId, 'tag_id' => $tagId]);
        DB::table('workspace_smtp_configs')->insert([
            'workspace_id' => $workspace->id,
            'host' => 'mail.example.test',
            'port' => 587,
            'username' => 'user',
            'password' => 'encrypted',
            'encryption' => 'tls',
            'from_email' => 'user@example.test',
            'from_name' => 'User',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($ctx['user'])
            ->delete(route('client.workspaces.destroy', $workspace), ['confirmation' => $workspace->name])
            ->assertRedirect(route('client.workspaces.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('current_workspace_id', $ctx['second']->id);

        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('contacts', ['id' => $contactId]);
        $this->assertDatabaseMissing('contact_tags', ['id' => $tagId]);
        $this->assertDatabaseMissing('contact_tag_pivot', ['contact_id' => $contactId]);
        $this->assertDatabaseMissing('workspace_smtp_configs', ['workspace_id' => $workspace->id]);
        $this->assertSame($ctx['second']->id, $ctx['user']->refresh()->workspace_id);
    }

    public function test_client_administrator_is_given_safe_fallback_when_deleting_their_only_assigned_workspace(): void
    {
        $ctx = $this->createWorkspaceContext();
        $otherOwner = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $ctx['client']->id,
            'client_role' => User::CLIENT_ROLE_STAFF,
            'status' => User::STATUS_ACTIVE,
        ]);
        $second = Workspace::create([
            'client_id' => $ctx['client']->id,
            'owner_id' => $otherOwner->id,
            'name' => 'Other team workspace',
        ]);
        $second->members()->attach($otherOwner->id, ['role' => 'owner']);

        $this->assertFalse($ctx['user']->canAccessWorkspace($second));

        $this->actingAs($ctx['user'])
            ->delete(route('client.workspaces.destroy', $ctx['workspace']), ['confirmation' => $ctx['workspace']->name])
            ->assertRedirect(route('client.workspaces.index'))
            ->assertSessionHas('current_workspace_id', $second->id);

        $this->assertSame($second->id, $ctx['user']->refresh()->workspace_id);
        $this->assertTrue($ctx['user']->canAccessWorkspace($second));
    }

    /** @return array{user:User,workspace:Workspace,second:Workspace} */
    private function contextWithSecondWorkspace(): array
    {
        $ctx = $this->createWorkspaceContext();
        $second = Workspace::create([
            'client_id' => $ctx['client']->id,
            'owner_id' => $ctx['user']->id,
            'name' => 'Second workspace',
        ]);
        $second->members()->attach($ctx['user']->id, ['role' => 'owner']);

        return [...$ctx, 'second' => $second];
    }
}
