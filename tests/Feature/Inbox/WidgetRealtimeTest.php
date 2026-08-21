<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageSent;
use App\Events\WidgetMessageCreated;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\WidgetPushSubscription;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WidgetRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_broadcast_auth_accepts_only_the_token_bound_conversation(): void
    {
        config([
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
            'broadcasting.connections.pusher.options.cluster' => 'mt1',
        ]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
        ])->assertOk();

        $conversationId = $session->json('conversation_id');

        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.broadcasting-auth'), [
                'key' => $widget->widget_key,
                'socket_id' => '123.456',
                'channel_name' => "private-widget-conversation.{$conversationId}",
            ])
            ->assertOk()
            ->assertJsonStructure(['auth']);

        $this->withHeader('X-Widget-Token', $session->json('token'))
            ->postJson(route('widget.broadcasting-auth'), [
                'key' => $widget->widget_key,
                'socket_id' => '123.456',
                'channel_name' => 'private-widget-conversation.999999',
            ])
            ->assertForbidden();
    }

    public function test_widget_broadcast_auth_rejects_disallowed_domains(): void
    {
        config([
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id, [
            'allowed_domains' => ['allowed.example'],
        ]);

        $session = $this->withHeader('Origin', 'https://allowed.example')
            ->postJson(route('widget.session'), ['key' => $widget->widget_key])
            ->assertOk();

        $this->withHeaders([
            'Origin' => 'https://evil.example',
            'X-Widget-Token' => $session->json('token'),
        ])->postJson(route('widget.broadcasting-auth'), [
            'key' => $widget->widget_key,
            'socket_id' => '123.456',
            'channel_name' => 'private-widget-conversation.'.$session->json('conversation_id'),
        ])->assertForbidden();
    }

    public function test_widget_session_can_store_optional_sdk_push_token(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        [$widget] = $this->createWebchatWidget($workspace->id);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'push' => [
                'token' => 'onesignal-subscription-123',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('widget_push_subscriptions', [
            'workspace_id' => $workspace->id,
            'chat_widget_id' => $widget->id,
            'conversation_id' => $session->json('conversation_id'),
            'visitor_id' => $session->json('visitor_id'),
            'onesignal_subscription_id' => 'onesignal-subscription-123',
            'revoked_at' => null,
        ]);
    }

    public function test_agent_and_bot_webchat_replies_broadcast_widget_safe_payloads(): void
    {
        Event::fake([WidgetMessageCreated::class]);

        ['workspace' => $workspace, 'user' => $agent] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        $agentMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Agent reply',
            'status' => 'sent',
            'sent_by' => 'agent',
            'user_id' => $agent->id,
            'provider_message_id' => 'private-provider-id',
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($agentMessage);

        Event::assertDispatched(WidgetMessageCreated::class, function (WidgetMessageCreated $event) use ($conversation) {
            return $event->conversationId === $conversation->id
                && $event->message['role'] === 'agent'
                && $event->message['body'] === 'Agent reply'
                && ! array_key_exists('provider_message_id', $event->message);
        });

        $botMessage = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Bot reply',
            'status' => 'sent',
            'sent_by' => 'bot',
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($botMessage);

        Event::assertDispatched(WidgetMessageCreated::class, function (WidgetMessageCreated $event) use ($conversation, $widget) {
            return $event->conversationId === $conversation->id
                && $event->message['body'] === 'Bot reply'
                && $event->message['agent_name'] === ($widget->agent_name ?: 'Support');
        });
    }

    public function test_agent_webchat_reply_notifies_registered_sdk_push_subscription(): void
    {
        Event::fake([WidgetMessageCreated::class]);
        Http::fake(['https://api.onesignal.com/notifications' => Http::response(['id' => 'push-id'], 200)]);
        config([
            'services.onesignal.app_id' => 'onesignal-app-id',
            'services.onesignal.rest_api_key' => 'onesignal-rest-key',
        ]);

        ['workspace' => $workspace, 'user' => $agent] = $this->createWorkspaceContext();
        [$widget, $account] = $this->createWebchatWidget($workspace->id);
        $conversation = $this->createConversation($workspace->id, $account->id);

        WidgetPushSubscription::create([
            'workspace_id' => $workspace->id,
            'chat_widget_id' => $widget->id,
            'conversation_id' => $conversation->id,
            'visitor_id' => 'visitor-123',
            'onesignal_subscription_id' => 'sdk-subscription-123',
            'last_seen_at' => now(),
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'webchat',
            'type' => 'text',
            'body' => 'Agent reply for mobile SDK',
            'status' => 'sent',
            'sent_by' => 'agent',
            'user_id' => $agent->id,
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($message);

        Http::assertSent(function ($request) use ($conversation) {
            $payload = $request->data();

            return $request->url() === 'https://api.onesignal.com/notifications'
                && $payload['app_id'] === 'onesignal-app-id'
                && $payload['include_subscription_ids'] === ['sdk-subscription-123']
                && $payload['target_channel'] === 'push'
                && $payload['contents']['en'] === 'Agent reply for mobile SDK'
                && $payload['data']['type'] === 'widget_message'
                && $payload['data']['conversation_id'] === $conversation->id;
        });
    }

    public function test_non_webchat_replies_do_not_broadcast_widget_events(): void
    {
        Event::fake([WidgetMessageCreated::class]);

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $conversation = $this->createConversation($workspace->id, $account->id);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'WhatsApp reply',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        MessageSent::dispatch($message);

        Event::assertNotDispatched(WidgetMessageCreated::class);
    }

    /**
     * @return array{0:ChatWidget,1:ChannelAccount}
     */
    private function createWebchatWidget(int $workspaceId, array $widgetAttrs = []): array
    {
        $account = ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);

        $widget = ChatWidget::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
        ], $widgetAttrs));

        return [$widget, $account];
    }

    private function createConversation(int $workspaceId, int $channelAccountId): Conversation
    {
        $contact = Contact::factory()->create(['workspace_id' => $workspaceId]);

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $channelAccountId,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }
}
