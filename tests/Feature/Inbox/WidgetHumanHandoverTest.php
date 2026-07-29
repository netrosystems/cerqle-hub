<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageReceived;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use App\Notifications\ConversationHandoverNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WidgetHumanHandoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_widget_offers_and_persists_human_handover_after_two_visitor_messages(): void
    {
        Event::fake([MessageReceived::class]);
        Notification::fake();
        ['workspace' => $workspace, 'user' => $user] = $this->createWorkspaceContext();

        $chatbot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Support AI',
            'enabled' => true,
        ]);
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
            'meta_json' => ['ai_chatbot_id' => $chatbot->id],
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_enabled' => true,
            'ai_chatbot_id' => $chatbot->id,
        ]);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'handover-device',
        ])->assertOk()
            ->assertJsonPath('config.ai_enabled', true)
            ->assertJsonPath('handover.available', true)
            ->assertJsonPath('handover.visitor_message_count', 0);

        $first = $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'First question',
            ])->assertOk()
            ->assertJsonPath('handover.visitor_message_count', 1);

        $this->withHeaders(['X-Widget-Token' => $first->json('token')])
            ->postJson(route('widget.handover'), ['key' => $widget->widget_key])
            ->assertStatus(422);

        $second = $this->withHeaders(['X-Widget-Token' => $first->json('token')])
            ->postJson(route('widget.send'), [
                'key' => $widget->widget_key,
                'message' => 'Second question',
            ])->assertOk()
            ->assertJsonPath('handover.visitor_message_count', 2);

        $this->withHeaders(['X-Widget-Token' => $first->json('token')])
            ->postJson(route('widget.handover'), ['key' => $widget->widget_key])
            ->assertOk()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('handover.requested', true);

        $conversation = Conversation::sole();
        $this->assertSame('human', $conversation->assigned_to);
        $this->assertNotNull($conversation->handover_at);

        Notification::assertSentTo(
            $user,
            ConversationHandoverNotification::class,
            fn (ConversationHandoverNotification $notification) => $notification->reason === 'widget_request'
        );

        $this->withHeaders(['X-Widget-Token' => $first->json('token')])
            ->getJson(route('widget.poll', [
                'key' => $widget->widget_key,
                'after' => 0,
            ]))
            ->assertOk()
            ->assertJsonPath('handover.requested', true);
    }

    public function test_handover_is_not_available_without_an_enabled_ai_chatbot(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'webchat',
            'display_name' => 'Website chat',
            'status' => 'active',
        ]);
        $widget = ChatWidget::create([
            'workspace_id' => $workspace->id,
            'channel_account_id' => $account->id,
            'name' => 'Website chat',
            'position' => 'bottom_right',
            'ai_enabled' => false,
        ]);

        $session = $this->postJson(route('widget.session'), [
            'key' => $widget->widget_key,
            'visitor_id' => 'normal-device',
        ])->assertOk()
            ->assertJsonPath('config.ai_enabled', false)
            ->assertJsonPath('handover.available', false);

        $this->withHeaders(['X-Widget-Token' => $session->json('token')])
            ->postJson(route('widget.handover'), ['key' => $widget->widget_key])
            ->assertStatus(422);
    }
}
