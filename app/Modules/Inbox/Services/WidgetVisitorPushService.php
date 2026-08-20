<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Models\WidgetPushSubscription;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\OneSignalService;
use Illuminate\Support\Str;

class WidgetVisitorPushService
{
    public function __construct(private readonly OneSignalService $oneSignal) {}

    public function register(ChatWidget $widget, Conversation $conversation, string $visitorId, ?string $token): void
    {
        $token = trim((string) $token);

        if ($token === '') {
            return;
        }

        WidgetPushSubscription::updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'onesignal_subscription_id' => $token,
            ],
            [
                'workspace_id' => $widget->workspace_id,
                'chat_widget_id' => $widget->id,
                'visitor_id' => $visitorId,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $widgetMessage
     */
    public function notifyReply(Conversation $conversation, ChatWidget $widget, Message $message, array $widgetMessage): void
    {
        $tokens = WidgetPushSubscription::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('revoked_at')
            ->pluck('onesignal_subscription_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($tokens === []) {
            return;
        }

        $title = (string) ($widgetMessage['agent_name'] ?? $widget->agent_name ?? 'Support');
        $body = trim((string) ($message->body ?: $widgetMessage['body'] ?? 'New message'));
        $body = $body !== '' ? Str::limit($body, 180) : 'New message';

        $this->oneSignal->sendToSubscriptionIds($tokens, $title, $body, null, $conversation->id, [
            'type' => 'widget_message',
            'conversation_id' => $conversation->id,
        ]);
    }
}
