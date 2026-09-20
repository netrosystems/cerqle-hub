<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

class AiReplyEligibility
{
    public function humanOwned(Conversation $conversation, string $channel): bool
    {
        if ($conversation->assigned_user_id || ($channel !== 'email' && $conversation->assigned_to === 'human' && ! $this->botMayContinue($conversation))) {
            return true;
        }
        if ($conversation->handover_at && ! $this->botMayContinue($conversation)) {
            return true;
        }

        return $this->humanHasReplied($conversation);
    }

    /**
     * After a handover the bot used to fall silent immediately and stay silent,
     * so a customer who asked for a person at 3am got nothing at all until
     * someone logged in.
     *
     * While nobody is available and no human has actually replied yet, the bot
     * keeps helping. It stands down the moment a person joins — which is the
     * condition that already existed.
     */
    private function botMayContinue(Conversation $conversation): bool
    {
        if (! config('ai.smart_bot.bot_continues_after_unanswered_handoff')) {
            return false;
        }
        // An explicit assignment means a named person took the conversation.
        if ($conversation->assigned_user_id || $this->humanHasReplied($conversation)) {
            return false;
        }

        return ! app(AgentAvailability::class)->forConversation($conversation);
    }

    private function humanHasReplied(Conversation $conversation): bool
    {
        return $conversation->messages()->where('direction', 'out')->where('sent_by', 'human')
            ->when($conversation->ai_handback_after_message_id !== null, fn ($q) => $q->where('id', '>', $conversation->ai_handback_after_message_id))->exists();
    }

    public function suppressed(Message $message): bool
    {
        if ($message->origin === 'whatsapp_history' || trim((string) $message->body) === '') {
            return true;
        }
        if ($message->channel !== 'email') {
            return false;
        }
        $payload = $message->payload ?? [];
        $from = strtolower((string) ($payload['from_address'] ?? $message->conversation?->contact?->email));
        $self = strtolower((string) ($message->conversation?->channelAccount?->meta_json['email'] ?? ''));
        $headers = $payload['mail_headers'] ?? [];

        return ! empty($payload['history_import']) || $from === $self
            || preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/i', $from)
            || (isset($headers['auto-submitted']) && strtolower(trim($headers['auto-submitted'])) !== 'no')
            || isset($headers['list-id']) || isset($headers['list-unsubscribe'])
            || strtolower($headers['x-auto-response-suppress'] ?? '') === 'all'
            || in_array(strtolower($headers['precedence'] ?? ''), ['bulk', 'list', 'junk'], true)
            || str_contains(strtolower($headers['content-type'] ?? ''), 'report-type=delivery-status');
    }
}
