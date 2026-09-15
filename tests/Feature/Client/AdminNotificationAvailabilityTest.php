<?php

namespace Tests\Feature\Client;

use App\Models\NotificationAvailability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNotificationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $ctx): User
    {
        $user = User::factory()->create(['client_id' => $ctx['client']->id, 'client_role' => User::CLIENT_ROLE_STAFF, 'workspace_id' => $ctx['workspace']->id, 'status' => 'active']);
        $ctx['workspace']->members()->syncWithoutDetaching([$user->id => ['role' => 'staff']]);

        return $user;
    }

    private function url(User $member, Workspace $workspace): string
    {
        return "/app/team/{$member->id}/workspaces/{$workspace->id}/notification-availability";
    }

    public function test_admin_sets_assigned_member_schedule_and_staff_cannot_change_it(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $member = $this->staff($ctx);
        $url = $this->url($member, $ctx['workspace']);
        $this->actingAs($ctx['user'])->getJson($url)->assertOk()->assertJsonPath('can_edit', true)->assertJsonPath('member_id', $member->id);
        $this->patchJson($url, ['mode' => 'paused', 'revision' => 0])->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseHas('notification_availabilities', ['user_id' => $member->id, 'mode' => 'paused']);
        $this->actingAs($member)->getJson('/app/settings/notification-availability')->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('mode', 'paused');
        $this->patchJson('/app/settings/notification-availability', ['mode' => 'always', 'revision' => 1])->assertForbidden();
        $this->patchJson($url, ['mode' => 'always', 'revision' => 1])->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->assertDatabaseHas('notification_availabilities', ['user_id' => $member->id, 'mode' => 'paused', 'revision' => 1]);
    }

    public function test_mobile_staff_write_is_forbidden_but_read_remains_available(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $member = $this->staff($ctx);
        Sanctum::actingAs($member, ['*']);
        $this->getJson('/api/v1/notification-availability')->assertOk()->assertJsonPath('can_edit', false);
        $this->patchJson('/api/v1/notification-availability', ['mode' => 'paused', 'revision' => 0])->assertForbidden();
        $this->assertSame(0, NotificationAvailability::count());
    }

    public function test_admin_cannot_manage_foreign_client_or_unassigned_members(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $foreign = $this->createSubscribedWorkspaceContext();
        $member = $this->staff($ctx);
        $this->actingAs($ctx['user'])->patchJson($this->url($foreign['user'], $foreign['workspace']), ['mode' => 'paused', 'revision' => 0])->assertNotFound();
        $this->getJson($this->url($member, $foreign['workspace']))->assertNotFound();
        $ctx['workspace']->members()->detach($member);
        $this->patchJson($this->url($member, $ctx['workspace']), ['mode' => 'paused', 'revision' => 0])->assertNotFound();
        $this->assertSame(0, NotificationAvailability::count());
    }

    public function test_admin_revision_conflicts_and_workspace_isolation(): void
    {
        $ctx = $this->createSubscribedWorkspaceContext();
        $member = $this->staff($ctx);
        $second = Workspace::factory()->create(['owner_id' => $ctx['user']->id, 'client_id' => $ctx['client']->id]);
        $second->members()->attach($member, ['role' => 'staff']);
        $this->actingAs($ctx['user'])->patchJson($this->url($member, $ctx['workspace']), ['mode' => 'paused', 'revision' => 0])->assertOk();
        $this->patchJson($this->url($member, $ctx['workspace']), ['mode' => 'always', 'revision' => 0])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->getJson($this->url($member, $second))->assertOk()->assertJsonPath('mode', 'always');
        $this->assertSame(1, NotificationAvailability::count());
    }
}
