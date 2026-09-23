<?php

namespace Tests\Feature\Inbox;

use App\Http\Controllers\Api\V1\MobileConversationController;
use App\Http\Controllers\Api\V1\MobileEmailInboxController;
use App\Models\User;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\ConversationActivityService;
use App\Modules\Inbox\Services\EmailBulkResolveService;
use App\Modules\Inbox\Services\WidgetPayloadBuilder;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConversationActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_join_and_resolve_are_durable_without_changing_message_preview_or_unread_count(): void
    {
        [$conversation, $widget, $actor] = $this->chat();
        $inbound = $conversation->messages()->create([
            'direction' => 'in', 'channel' => 'webchat', 'type' => 'text',
            'body' => 'Hello', 'status' => 'delivered', 'sent_at' => now()->subMinute(),
        ]);
        $conversation->update(['last_message_at' => $inbound->sent_at, 'unread_count' => 2]);

        $service = app(ConversationActivityService::class);
        $service->join($conversation, $actor);
        $service->join($conversation, $actor);
        $service->status($conversation, 'resolved', $actor);
        $service->status($conversation, 'resolved', $actor);

        $activities = $conversation->messages()->where('direction', 'system')->orderBy('id')->get();
        $this->assertCount(2, $activities);
        $this->assertSame(['conversation.joined', 'conversation.resolved'], $activities->pluck('payload')->map(fn ($payload) => $payload['activity']['type'])->all());
        $this->assertSame($actor->name, $activities[0]->payload['activity']['actor']['name']);
        $this->assertNull($activities[0]->provider_message_id);
        $this->assertSame(2, $conversation->fresh()->unread_count);
        $this->assertEquals($inbound->sent_at, $conversation->fresh()->last_message_at);
        $this->assertSame($inbound->id, $conversation->fresh()->lastMessage->id);

        $public = app(WidgetPayloadBuilder::class)->messages($conversation->id, $widget, 0);
        $this->assertSame(['visitor', 'agent', 'agent'], array_column($public, 'role'));
        $this->assertSame('activity', $public[1]['kind']);
        $this->assertSame(['type' => 'conversation.joined', 'actor_name' => $actor->name], $public[1]['activity']);
        $this->assertArrayNotHasKey('id', $public[1]['activity']);
        $this->assertArrayNotHasKey('payload', $public[1]);
    }

    public function test_assignment_is_staff_only_and_new_agent_join_is_public(): void
    {
        [$conversation, $widget, $actor, $workspace] = $this->chat();
        $other = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $actor->client_id,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
        ]);
        $other->workspaces()->syncWithoutDetaching([$workspace->id => ['role' => 'agent']]);

        $service = app(ConversationActivityService::class);
        $service->join($conversation, $actor);
        $service->assign($conversation, $other, $actor);
        $this->assertNull($conversation->fresh()->joined_user_id);
        $service->join($conversation, $other);

        $this->assertSame(
            ['conversation.joined', 'conversation.assigned', 'conversation.joined'],
            $conversation->messages()->where('direction', 'system')->orderBy('id')->get()
                ->map(fn ($message) => $message->payload['activity']['type'])->all(),
        );
        $public = app(WidgetPayloadBuilder::class)->messages($conversation->id, $widget, 0);
        $this->assertCount(2, $public);
        $this->assertSame($other->name, $public[1]['activity']['actor_name']);
    }

    public function test_failed_join_does_not_create_activity(): void
    {
        [$conversation, , $actor] = $this->chat();
        $conversation->update(['status' => 'resolved']);

        try {
            app(ConversationActivityService::class)->join($conversation, $actor);
            $this->fail('Expected a conflict for a resolved conversation.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(0, $conversation->messages()->count());
        $this->assertNull($conversation->fresh()->joined_user_id);
    }

    public function test_takeover_is_staff_transfer_but_public_join_and_foreign_actor_is_rejected(): void
    {
        [$conversation, $widget, $actor, $workspace] = $this->chat();
        $admin = User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $actor->client_id,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
        ]);
        $admin->workspaces()->syncWithoutDetaching([$workspace->id => ['role' => 'agent']]);
        $foreign = User::factory()->create();
        $service = app(ConversationActivityService::class);
        $service->join($conversation, $actor);
        $service->takeover($conversation, $admin);

        $this->assertSame('conversation.transferred', $conversation->messages()->latest('id')->first()->payload['activity']['type']);
        $this->assertSame('conversation.joined', app(WidgetPayloadBuilder::class)->messages($conversation->id, $widget, 0)[1]['activity']['type']);
        $this->assertSame($admin->name, app(WidgetPayloadBuilder::class)->messages($conversation->id, $widget, 0)[1]['activity']['actor_name']);

        try {
            $service->status($conversation, 'resolved', $foreign);
            $this->fail('Expected a workspace authorization failure.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('open', $conversation->fresh()->status);
    }

    public function test_mobile_conversation_api_exposes_system_activity_and_joined_agent(): void
    {
        [$conversation, , $actor] = $this->chat();
        $conversation->messages()->create([
            'direction' => 'in', 'channel' => 'webchat', 'type' => 'text',
            'body' => 'Hello', 'status' => 'delivered', 'sent_at' => now(),
        ]);
        app(ConversationActivityService::class)->join($conversation, $actor);
        $request = Request::create("/api/v1/mobile/conversations/{$conversation->uuid}");
        $request->setUserResolver(fn () => $actor);
        $response = app(MobileConversationController::class)->show($request, $conversation->uuid);

        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertCount(2, $data['messages']);
        $this->assertSame('Hello', $data['messages'][0]['body']);
        $this->assertSame('system', $data['messages'][1]['direction']);
        $this->assertSame('event', $data['messages'][1]['type']);
        $this->assertSame('conversation.joined', $data['messages'][1]['payload']['activity']['type']);
        $this->assertSame($actor->id, $data['messages'][1]['sender']['id']);
        $this->assertSame($actor->id, $data['conversation']['joined_user']['id']);
        $this->assertNotNull($data['conversation']['joined_at']);

        $messagesRequest = Request::create("/api/v1/mobile/conversations/{$conversation->uuid}/messages");
        $messagesRequest->setUserResolver(fn () => $actor);
        $messagesResponse = app(MobileConversationController::class)->messages($messagesRequest, $conversation->uuid);
        $this->assertSame(['in', 'system'], array_column($messagesResponse->getData(true)['data'], 'direction'));
    }

    public function test_bulk_email_resolve_records_one_activity_per_open_thread(): void
    {
        [, , $actor, $workspace] = $this->chat();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id, 'channel' => 'email',
            'display_name' => 'Inbox', 'status' => 'active',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => $contact->id, 'status' => 'open',
        ]);

        $service = app(EmailBulkResolveService::class);
        $this->assertSame(1, $service->resolve($workspace->id, $account->id, $actor));
        $this->assertSame(0, $service->resolve($workspace->id, $account->id, $actor));
        $this->assertSame(1, $conversation->messages()->where('direction', 'system')->count());
        $this->assertSame('conversation.resolved', $conversation->messages()->first()->payload['activity']['type']);

        $request = Request::create("/api/v1/mobile/email/threads/{$conversation->uuid}");
        $request->setUserResolver(fn () => $actor);
        $response = app(MobileEmailInboxController::class)->show($request, $conversation->uuid);
        $data = $response->getData(true);

        $this->assertSame('system', $data['messages'][0]['direction']);
        $this->assertSame('event', $data['messages'][0]['type']);
        $this->assertSame('conversation.resolved', $data['messages'][0]['payload']['activity']['type']);
        $this->assertSame($actor->id, $data['messages'][0]['user']['id']);
    }

    private function chat(): array
    {
        ['user' => $actor, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id, 'channel' => 'webchat',
            'display_name' => 'Website chat', 'status' => 'active',
        ]);
        $widget = new ChatWidget([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'name' => 'Website chat', 'position' => 'bottom_right',
        ]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id, 'channel_account_id' => $account->id,
            'contact_id' => $contact->id, 'status' => 'open',
        ]);

        return [$conversation, $widget, $actor, $workspace];
    }
}
