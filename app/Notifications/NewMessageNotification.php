<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\Channels\OneSignalChannel;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Message $message,
        public readonly Conversation $conversation,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        // Email is intentionally not offered for new-message notifications: an
        // email per inbound message is too noisy. Use web push / OneSignal instead.

        $oneSignalConfigured = app(OneSignalChannel::class)->isConfigured();

        // OneSignal is the primary push provider. Keep native VAPID only as a
        // fallback for deployments that have not configured OneSignal yet.
        if (! $oneSignalConfigured && $this->isEnabled($notifiable, 'web_push')) {
            $channels[] = WebPushChannel::class;
        }

        // OneSignal push: always send when configured so the user gets notified
        // even when the browser is closed. Client-side foregroundWillDisplay
        // suppresses the notification when the inbox is already open.
        if ($oneSignalConfigured && $this->isEnabled($notifiable, 'one_signal')) {
            $channels[] = OneSignalChannel::class;
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_message',
            'message_id' => $this->message->id,
            'conversation_id' => $this->conversation->id,
            'contact_name' => $this->conversation->contact?->name ?? 'Unknown',
            'snippet' => mb_substr((string) $this->message->body, 0, 120),
            'channel' => $this->message->channel,
            'conversation_uuid' => $this->conversation->uuid,
            'workspace_id' => $this->conversation->workspace_id,
            'screen' => $this->isEmail() ? 'master_email_inbox' : 'omni_channel_inbox',
            'url' => $this->destinationUrl(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * Safety net only — via() never returns the 'mail' channel (new-message
     * emails are intentionally disabled as too noisy). This method exists so a
     * stale 'mail' job left in the queue from before that change drains without
     * fataling the worker (queued notifications bake the channel in at dispatch
     * time). It can be removed once no legacy 'mail' jobs remain.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New message from '.($this->conversation->contact?->name ?? 'a contact'))
            ->line('You have a new message in your inbox.')
            ->action('View Conversation', route('client.inbox.show', $this->conversation));
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'New message',
            'body' => $this->conversation->contact?->name ?? 'A contact sent a message',
            'url' => $this->destinationUrl(),
        ];
    }

    public function toOneSignal(object $notifiable): array
    {
        $contact = $this->conversation->contact;
        $name = trim(implode(' ', array_filter([$contact?->first_name, $contact?->last_name])));
        $channel = ucfirst($this->conversation->channel_account?->channel ?? 'message');
        $snippet = mb_substr((string) $this->message->body, 0, 100);

        return [
            'title' => $name ?: 'New message',
            'body' => $snippet ?: "New {$channel} message",
            'url' => $this->destinationUrl(),
            // Extra data so the service worker can collapse duplicate notifications
            // for the same conversation.
            'conversation_id' => $this->conversation->id,
            'conversation_uuid' => $this->conversation->uuid,
            'workspace_id' => $this->conversation->workspace_id,
            'channel' => $this->message->channel,
            'screen' => $this->isEmail() ? 'master_email_inbox' : 'omni_channel_inbox',
            'account_id' => $this->conversation->channel_account_id,
        ];
    }

    private function isEmail(): bool
    {
        return $this->message->channel === 'email';
    }

    private function destinationUrl(): string
    {
        if ($this->isEmail()) {
            return route('client.inbox.email-inbox', ['conversation' => $this->conversation->uuid]);
        }

        return route('client.inbox.show', $this->conversation);
    }

    private function isEnabled(object $notifiable, string $channel): bool
    {
        $pref = NotificationPreference::where('user_id', $notifiable->id)
            ->where('event', 'new_message')
            ->where('channel', $channel)
            ->first();

        return $pref === null || $pref->enabled;
    }
}
