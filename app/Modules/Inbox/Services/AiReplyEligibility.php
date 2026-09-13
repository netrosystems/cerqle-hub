<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

class AiReplyEligibility
{
    public function humanOwned(Conversation $conversation, string $channel): bool
    {
        if ($conversation->assigned_user_id || $conversation->handover_at || ($channel !== 'email' && $conversation->assigned_to === 'human')) {
            return true;
        }

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
