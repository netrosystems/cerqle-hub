<?php

namespace Tests\Feature\Realtime;

use App\Http\Controllers\Api\V1\NotificationApiController;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\AutomationFailedNotification;
use App\Notifications\CampaignCompletedNotification;
use App\Notifications\ConversationAssignedNotification;
use App\Notifications\ConversationHandoverNotification;
use App\Notifications\MentionedInNoteNotification;
use App\Notifications\PendingCustomerReplyNotification;
use App\Notifications\WorkspaceExportReadyNotification;
use App\Services\WorkspaceNotifications;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function alert(User $user, ?int $workspaceId): string
    {
        $id = (string) Str::uuid();
        $user->notifications()->create([
            'id' => $id,
            'type' => 'test',
            'data' => $workspaceId ? ['workspace_id' => $workspaceId] : [],
        ]);

        return $id;
    }

    public function test_lists_counts_and_actions_are_workspace_scoped(): void
    {
        $ctx = $this->createWorkspaceContext();
        $user = $ctx['user'];
        $a = $ctx['workspace'];
        $b = Workspace::create(['name' => 'Second', 'client_id' => $ctx['client']->id, 'owner_id' => $user->id]);
        $mine = $this->alert($user, $a->id);
        $other = $this->alert($user, $b->id);
        $legacy = $this->alert($user, null);
        $this->actingAs($user)->withSession(['current_workspace_id' => $a->id]);

        $this->getJson('/app/notifications/recent')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mine);
        $this->getJson('/app/notifications/unread-count')->assertJsonPath('count', 1);
        $this->get('/app/notifications')->assertInertia(fn ($page) => $page
            ->has('notifications', 1)
            ->where('notifications.0.id', $mine)
            ->where('unreadNotificationsCount', 1));
        $this->postJson("/app/notifications/{$other}/read")->assertNotFound();
        $this->deleteJson("/app/notifications/{$other}")->assertNotFound();
        $this->post('/app/notifications/read-all')->assertRedirect();
        $this->assertNotNull($user->notifications()->find($mine)->read_at);
        $this->assertNull($user->notifications()->find($other)->read_at);
        $this->assertNull($user->notifications()->find($legacy)->read_at);

        $this->withSession(['current_workspace_id' => $b->id]);
        $this->getJson('/app/notifications/recent')->assertJsonCount(1)->assertJsonPath('0.id', $other);
        $this->postJson("/app/notifications/{$other}/read")->assertOk();
        $this->deleteJson("/app/notifications/{$other}")->assertOk();
    }

    public function test_inaccessible_workspace_and_other_recipients_are_excluded(): void
    {
        $a = $this->createWorkspaceContext();
        $b = $this->createWorkspaceContext();
        $this->alert($a['user'], $b['workspace']->id);
        $this->alert($b['user'], $a['workspace']->id);
        $this->assertSame(0, WorkspaceNotifications::forUser($a['user'], $b['workspace']->id)->count());
        $this->assertSame(0, WorkspaceNotifications::forUser($a['user'], $a['workspace']->id)->count());
        $this->assertSame(0, WorkspaceNotifications::forUser($a['user'], null)->count());
    }

    public function test_api_uses_users_workspace_without_a_session(): void
    {
        $a = $this->createWorkspaceContext();
        $b = $this->createWorkspaceContext();
        $mine = $this->alert($a['user'], $a['workspace']->id);
        $other = $this->alert($a['user'], $b['workspace']->id);
        $request = Request::create('/api/v1/notifications');
        $request->setUserResolver(fn () => $a['user']);
        $controller = new NotificationApiController;
        $data = $controller->index($request)->getData(true);
        $this->assertCount(1, $data['data']);
        $this->assertSame($mine, $data['data'][0]['id']);
        $this->expectException(ModelNotFoundException::class);
        $controller->markRead($request, $other);
    }

    public function test_workspace_payloads_use_source_not_recipients_selected_workspace(): void
    {
        $user = new User(['workspace_id' => 999]);
        $conversation = new Conversation(['workspace_id' => 42]);
        $conversation->id = 123;
        $conversation->uuid = (string) Str::uuid();
        $conversation->setRelation('contact', null);
        $message = new Message(['body' => 'Test']);
        $campaign = new Campaign(['workspace_id' => 42]);
        $run = new AutomationRun;
        $run->setRelation('automation', new Automation(['workspace_id' => 42]));

        $notifications = [
            new ConversationAssignedNotification($conversation, null),
            new ConversationHandoverNotification($conversation),
            new MentionedInNoteNotification($user, $conversation, 'Test'),
            new PendingCustomerReplyNotification($conversation, $message),
            new CampaignCompletedNotification($campaign),
            new AutomationFailedNotification($run, 'Test'),
            new WorkspaceExportReadyNotification('https://example.com/export', 42),
        ];
        foreach ($notifications as $notification) {
            $this->assertSame(42, $notification->toArray($user)['workspace_id']);
        }
    }
}
