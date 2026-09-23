<?php

namespace App\Listeners;

use App\Events\ConversationActivityCreated;
use App\Events\ConversationAssigned;
use App\Events\MessageSent;
use App\Events\TypingChanged;
use App\Events\WidgetHandoffUpdated;
use App\Events\WidgetMessageCreated;
use App\Events\WidgetTypingChanged;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\WidgetPayloadBuilder;
use App\Modules\Inbox\Services\WidgetVisitorPushService;
use App\Modules\Shared\Models\Conversation;

class BroadcastWidgetRealtimeUpdate
{
    public function __construct(
        private readonly WidgetPayloadBuilder $payloads,
        private readonly WidgetVisitorPushService $visitorPush,
    ) {}

    public function handleMessageSent(MessageSent $event): void
    {
        $message = $event->message->loadMissing(['conversation.channelAccount', 'sender']);
        $conversation = $message->conversation;
        $widget = $conversation ? $this->widgetFor($conversation) : null;

        if (! $widget || $message->direction !== 'out') {
            return;
        }

        $payload = $this->payloads->message($message, $widget);

        broadcast(new WidgetMessageCreated((int) $conversation->id, $payload));
        $this->visitorPush->notifyReply($conversation, $widget, $message, $payload);
    }

    public function handleConversationActivityCreated(ConversationActivityCreated $event): void
    {
        $message = $event->message->loadMissing('conversation.channelAccount');
        $conversation = $message->conversation;
        $widget = $conversation ? $this->widgetFor($conversation) : null;
        $type = $message->payload['activity']['type'] ?? null;
        if (! $widget || ! in_array($type, ['conversation.joined', 'conversation.resolved', 'conversation.transferred'], true)) {
            return;
        }

        broadcast(new WidgetMessageCreated((int) $conversation->id, $this->payloads->message($message, $widget)));
    }

    public function handleConversationAssigned(ConversationAssigned $event): void
    {
        $conversation = $event->conversation->loadMissing('channelAccount');
        $widget = $this->widgetFor($conversation);

        if (! $widget) {
            return;
        }

        broadcast(new WidgetHandoffUpdated(
            (int) $conversation->id,
            $this->payloads->handoff($widget, $conversation),
        ));
    }

    public function handleTypingChanged(TypingChanged $event): void
    {
        $conversation = $event->conversation->loadMissing('channelAccount');
        $widget = $this->widgetFor($conversation);

        if (! $widget) {
            return;
        }

        broadcast(new WidgetTypingChanged(
            (int) $conversation->id,
            $event->isTyping,
            $event->user->name,
        ));
    }

    private function widgetFor(Conversation $conversation): ?ChatWidget
    {
        if ($conversation->channelAccount?->channel !== 'webchat') {
            return null;
        }

        $enabledColumn = $conversation->started_from === Conversation::STARTED_FROM_CUSTOMER_SDK
            ? 'sdk_enabled'
            : 'enabled';

        return ChatWidget::where('channel_account_id', $conversation->channel_account_id)
            ->where('workspace_id', $conversation->workspace_id)
            ->where($enabledColumn, true)
            ->first();
    }
}
