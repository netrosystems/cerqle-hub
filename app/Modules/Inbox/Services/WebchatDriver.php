<?php

namespace App\Modules\Inbox\Services;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Channel driver for the website live-chat widget.
 *
 * Inbound: visitor messages arrive via the public widget API, which calls
 * {@see ingestVisitorMessage()} — the same find-or-create Contact + Conversation
 * + Message + MessageReceived flow the social drivers use, so AI auto-replies,
 * automations, outbound webhooks, agent real-time and notifications all work.
 *
 * Outbound (agent / AI reply): delivery to the visitor is by HTTP polling — the
 * outbound Message row is already persisted by the caller, and the widget fetches
 * it on its next poll, so {@see send()} is a no-op that just returns an id. (This
 * is the seam where a future WebSocket push to the visitor would live.)
 */
class WebchatDriver implements ChannelDriverInterface
{
    public function send(Message $message): string
    {
        return $message->provider_message_id ?: (string) Str::uuid();
    }

    public function receiveWebhook(Request $request): array
    {
        $widget = ChatWidget::where('widget_key', $request->input('key'))->first();
        if (! $widget) {
            return [];
        }

        $message = $this->ingestVisitorMessage(
            $widget,
            (string) $request->input('visitor_id'),
            (string) $request->input('message', ''),
            (array) $request->input('prechat', []),
        );

        return [$message];
    }

    public function verifyCreds(): bool
    {
        return true;
    }

    /**
     * Persist an inbound visitor message and return it. Reuses one conversation
     * per (workspace, visitor contact, widget channel account), reopening it if
     * it had been resolved — mirroring MessengerDriver::processInboundMessage().
     *
     * @param  array<string, mixed>  $identity  Optional {name, email, avatar, external_id}.
     */
    public function ingestVisitorMessage(ChatWidget $widget, string $visitorId, string $body, array $identity = []): Message
    {
        $conversation = $this->resolveConversation($widget, $visitorId, $identity);

        return $this->recordInboundMessage($conversation, $visitorId, $body);
    }

    /**
     * Persist an inbound visitor message onto an ALREADY-resolved conversation
     * and dispatch MessageReceived. The public API uses this on `send` (with the
     * conversation from the visitor's session token) so a message always lands on
     * the exact conversation the session is bound to — never re-resolved by
     * device id, which could split an identity-matched contact across devices.
     */
    public function recordInboundMessage(Conversation $conversation, string $visitorId, string $body, string $type = 'text', array $payload = []): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'webchat',
            'type' => $type,
            'payload' => array_merge(['visitor_id' => $visitorId], $payload),
            'body' => $body,
            'status' => 'delivered',
            'provider_message_id' => (string) Str::uuid(),
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        $conversation->update([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'status' => $conversation->status === 'resolved' ? 'open' : $conversation->status,
            'unread_count' => $conversation->unread_count + 1,
        ]);

        MessageReceived::dispatch($message);

        return $message;
    }

    /**
     * Find-or-create the visitor contact + their conversation without inserting a
     * message. Used on session init so the widget can load history and bind a
     * session token to the conversation before the first message is sent.
     *
     * @param  array<string, mixed>  $identity
     */
    public function resolveConversation(ChatWidget $widget, string $visitorId, array $identity = []): Conversation
    {
        $contact = $this->resolveVisitorContact($widget->workspace_id, $visitorId, $identity);

        return Conversation::firstOrCreate(
            [
                'workspace_id' => $widget->workspace_id,
                'contact_id' => $contact->id,
                'channel_account_id' => $widget->channelAccount?->id,
            ],
            [
                'status' => 'open',
                'assigned_to' => 'bot',
                'external_thread_id' => $visitorId,
            ],
        );
    }

    /**
     * Find-or-create the visitor as a Contact. A logged-in customer passed from
     * the client's site is matched on their stable external id
     * (custom_fields.webchat_external_id) so they map to ONE contact across
     * devices; anonymous visitors fall back to the per-device visitor id
     * (custom_fields.webchat_visitor_id, mirroring the Messenger PSID pattern).
     * Name / email / avatar from the client identity backfill the contact so the
     * agent sees who they are.
     *
     * @param  array<string, mixed>  $identity  {name, email, avatar, external_id}
     */
    private function resolveVisitorContact(int $workspaceId, string $visitorId, array $identity): Contact
    {
        $first = null;
        $last = null;
        $name = trim((string) ($identity['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name, 2) ?: [];
            $first = $parts[0] ?? null;
            $last = $parts[1] ?? null;
        }
        $email = filter_var((string) ($identity['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null;
        $avatar = (string) ($identity['avatar'] ?? '');
        $avatar = str_starts_with($avatar, 'http') ? $avatar : null; // only accept URLs
        $externalId = trim((string) ($identity['external_id'] ?? ''));

        $contact = null;
        if ($externalId !== '') {
            $contact = Contact::where('workspace_id', $workspaceId)
                ->whereJsonContains('custom_fields->webchat_external_id', $externalId)
                ->first();
        }
        if (! $contact) {
            $contact = Contact::where('workspace_id', $workspaceId)
                ->whereJsonContains('custom_fields->webchat_visitor_id', $visitorId)
                ->first();
        }

        if (! $contact) {
            $contact = Contact::create([
                'workspace_id' => $workspaceId,
                'source' => 'webchat',
                'first_name' => $first ?: 'Website visitor',
                'last_name' => $last,
                'email' => $email,
                'avatar' => $avatar,
                // Starting a chat or providing a pre-chat email address is not
                // marketing consent. Keep every marketing channel opted out
                // until the visitor explicitly grants permission.
                'opt_in_whatsapp' => false,
                'opt_in_sms' => false,
                'opt_in_email' => false,
                'custom_fields' => array_filter([
                    'webchat_visitor_id' => $visitorId,
                    'webchat_external_id' => $externalId ?: null,
                ]),
                'last_seen_at' => now(),
            ]);

            ContactCreated::dispatch($contact);

            return $contact;
        }

        // Link identifiers + fill any details we didn't already have (never
        // clobber an agent-edited contact).
        $cf = $contact->custom_fields ?? [];
        if ($externalId !== '' && empty($cf['webchat_external_id'])) {
            $cf['webchat_external_id'] = $externalId;
        }
        if (empty($cf['webchat_visitor_id'])) {
            $cf['webchat_visitor_id'] = $visitorId;
        }

        $updates = ['last_seen_at' => now(), 'custom_fields' => $cf];
        if ($first && (! $contact->first_name || $contact->first_name === 'Website visitor')) {
            $updates['first_name'] = $first;
            $updates['last_name'] = $last;
        }
        if ($email && ! $contact->email) {
            $updates['email'] = $email;
        }
        if ($avatar && ! $contact->avatar) {
            $updates['avatar'] = $avatar;
        }
        $contact->update($updates);

        return $contact;
    }
}
