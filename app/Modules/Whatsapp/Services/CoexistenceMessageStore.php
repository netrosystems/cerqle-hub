<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persistence boundary for non-live WhatsApp data. Does not send messages,
 * dispatch MessageReceived, fetch media, or grant contact consent.
 * Not wired to webhook ingress until durable import orchestration is ready.
 */
class CoexistenceMessageStore
{
    /** @param array<string, mixed> $message */
    public function store(string $wabaId, string $phoneId, array $message, bool $history, ?string $threadPhone = null): ?Message
    {
        $id = $message['id'] ?? null;
        $timestamp = $message['timestamp'] ?? null;
        if (! is_string($id) || $id === '' || strlen($id) > 128
            || ! is_scalar($timestamp) || ! ctype_digit((string) $timestamp)
            || (int) $timestamp < 946684800 || (int) $timestamp > now()->timestamp) {
            return null;
        }
        $sentAt = Carbon::createFromTimestamp((int) $timestamp);
        if ($sentAt->isFuture() || $sentAt->year < 2000) {
            return null;
        }

        return DB::transaction(function () use ($wabaId, $phoneId, $message, $history, $threadPhone, $id, $sentAt): ?Message {
            $phone = WhatsappPhoneNumber::query()
                ->where('phone_number_id', $phoneId)
                ->where('connection_mode', 'coexistence')
                ->whereHas('businessAccount', fn ($query) => $query->where('waba_id', $wabaId))
                ->lockForUpdate()->first();
            if (! $phone || ! empty($phone->coexistence_meta['disconnected_at'])
                || ($history && ! ($phone->coexistence_meta['import_consent'] ?? false))) {
                return null;
            }
            $workspaceId = (int) $phone->businessAccount->workspace_id;
            $channel = ChannelAccount::query()
                ->where('workspace_id', $workspaceId)->where('channel', 'whatsapp')
                ->where('phone_number_id', $phoneId)->where('business_account_id', $wabaId)
                ->first();
            if (! $channel) {
                return null;
            }
            $existing = Message::query()->where('provider_message_id', $id)
                ->where('channel', 'whatsapp')
                ->whereHas('conversation', fn ($query) => $query
                    ->where('workspace_id', $workspaceId)->where('channel_account_id', $channel->id))
                ->first();

            $type = is_string($message['type'] ?? null) ? $message['type'] : 'unsupported';
            $block = is_array($message[$type] ?? null) ? $message[$type] : [];
            $body = $block['body'] ?? $block['caption'] ?? null;
            $body = is_string($body) ? $body : null;
            $allowed = ['text', 'template', 'media', 'interactive', 'reaction', 'image', 'video',
                'document', 'audio', 'location', 'contacts', 'sticker', 'order', 'poll', 'event'];
            $storedType = in_array($type, $allowed, true) ? $type : 'unsupported';

            if ($existing) {
                // A history media follow-up can enrich only its own placeholder.
                // Never replace an existing live/API message or change its origin.
                if ($history && $existing->origin === 'whatsapp_history'
                    && ($existing->payload['type'] ?? '') === 'media_placeholder'
                    && in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
                    $existing->update(['type' => $storedType, 'body' => $body, 'payload' => $message]);
                }

                return $existing;
            }

            $businessPhone = preg_replace('/\D/', '', (string) $phone->getRawOriginal('display_phone'));
            $from = is_string($message['from'] ?? null) ? ltrim($message['from'], '+') : '';
            $to = is_string($message['to'] ?? null) ? ltrim($message['to'], '+') : '';
            $outbound = ! $history || ($businessPhone !== '' && $from === $businessPhone);
            $contactPhone = $threadPhone ? ltrim($threadPhone, '+') : ($outbound ? $to : $from);
            if ($businessPhone === '' || ! preg_match('/^[1-9][0-9]{5,14}$/', $contactPhone)
                || $contactPhone === $businessPhone
                || ! in_array($from, [$businessPhone, $contactPhone], true)
                || (! $history && ($from !== $businessPhone || $to !== $contactPhone))) {
                return null;
            }
            // A deletion in Cerqle must not be undone by a delayed import.
            $contact = Contact::withTrashed()->where('workspace_id', $workspaceId)
                ->where('phone_e164', '+'.$contactPhone)->first();
            if ($contact?->trashed()) {
                return null;
            }
            $contact ??= Contact::create([
                'workspace_id' => $workspaceId, 'phone_e164' => '+'.$contactPhone,
                'source' => 'whatsapp_coexistence',
                'opt_in_whatsapp' => false, 'opt_in_sms' => false, 'opt_in_email' => false,
            ]);
            $conversation = Conversation::firstOrCreate([
                'workspace_id' => $workspaceId, 'channel_account_id' => $channel->id,
                'contact_id' => $contact->id,
            ], ['external_thread_id' => $contactPhone, 'status' => 'open', 'unread_count' => 0]);

            $status = match ($message['history_context']['status'] ?? '') {
                'READ', 'PLAYED' => 'read', 'DELIVERED' => 'delivered',
                'ERROR' => 'failed', 'PENDING' => 'queued', default => 'sent',
            };
            $stored = Message::create([
                'conversation_id' => $conversation->id, 'channel' => 'whatsapp',
                'direction' => $outbound ? 'out' : 'in', 'type' => $storedType,
                'body' => $body, 'payload' => $message, 'status' => $status,
                'provider_message_id' => $id, 'sent_by' => 'human', 'user_id' => null,
                'sent_at' => $sentAt, 'origin' => $history ? 'whatsapp_history' : 'whatsapp_business_app',
            ]);
            $patch = [];
            if (! $conversation->last_message_at || $sentAt->gt($conversation->last_message_at)) {
                $patch['last_message_at'] = $sentAt;
            }
            if (! $history) {
                $patch['assigned_to'] = 'human';
                $patch['handover_at'] = now();
            }
            if ($patch !== []) {
                $conversation->update($patch);
            }

            return $stored;
        });
    }
}
