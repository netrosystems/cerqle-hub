<?php

namespace App\Modules\Inbox\Services;

use App\Modules\AI\Services\Smart\Choices;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

class WidgetPayloadBuilder
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(int $conversationId, ChatWidget $widget, int $afterId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->with('sender')
            ->where('id', '>', $afterId)
            ->whereIn('direction', ['in', 'out'])
            ->where('status', '!=', 'failed')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Message $message) => $this->message($message, $widget))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function message(Message $message, ChatWidget $widget): array
    {
        $message->loadMissing('sender');
        $isAgent = $message->direction === 'out';

        return [
            'id' => $message->id,
            'role' => $isAgent ? 'agent' : 'visitor',
            'status' => $message->status,
            'body' => (string) $message->body,
            'type' => $message->type,
            'attachment_url' => $this->browserSafePublicUrl($message->payload['preview_url'] ?? null),
            'filename' => $message->payload['filename'] ?? null,
            'mime_type' => $message->payload['mime_type'] ?? null,
            'file_size' => $message->payload['file_size'] ?? null,
            'quick_replies' => $this->quickReplies($message),
            'answer_origin' => $message->payload['ai_answer']['answer_origin'] ?? null,
            'response_mode' => $message->payload['ai_answer']['response_mode'] ?? null,
            'citations' => $message->payload['ai_answer']['citations'] ?? [],
            'handoff_offer' => (bool) ($message->payload['ai_answer']['handoff_offer'] ?? false),
            'sent_by' => $message->sent_by,
            'agent_name' => $isAgent
                ? ($message->sender?->name ?: ($widget->agent_name ?: 'Support'))
                : null,
            'created_at' => optional($message->sent_at ?? $message->created_at)->toIso8601String(),
        ];
    }

    /**
     * Choices as the widget should render them.
     *
     * With roles enabled a translated "talk to a person" reaches a human,
     * because the widget acts on the role rather than on the English wording.
     * While the flag is off the stored strings pass through untouched.
     *
     * @return list<mixed>
     */
    private function quickReplies(Message $message): array
    {
        $stored = $message->payload['ai_answer']['quick_replies'] ?? [];
        if (! config('ai.smart_bot.multilingual_handover')) {
            return is_array($stored) ? $stored : [];
        }

        return Choices::normalise($stored, (bool) ($message->payload['ai_answer']['handoff_offer'] ?? false));
    }

    /**
     * @return array{enabled:bool,eligible:bool,status:string}
     */
    public function handoff(ChatWidget $widget, Conversation $conversation): array
    {
        $enabled = true;
        $connected = ($conversation->assigned_to ?? 'bot') === 'human';

        return [
            'enabled' => $enabled,
            'eligible' => ! $connected && (! app(WidgetAiAvailability::class)->available($widget) || $this->hasTwoCustomerMessages($conversation)),
            'status' => $connected ? 'connected' : 'bot',
        ];
    }

    public function hasTwoCustomerMessages(Conversation $conversation): bool
    {
        return $conversation->messages()->where('direction', 'in')->count() >= 2;
    }

    private function browserSafePublicUrl(?string $url): ?string
    {
        if (! $url || ! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }

        $assetHost = parse_url($url, PHP_URL_HOST);
        $requestHost = request()->getHost();
        $shouldUseHttps = request()->isSecure() || app()->environment('production');

        if ($shouldUseHttps && $assetHost && strcasecmp($assetHost, $requestHost) === 0) {
            return 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }
}
