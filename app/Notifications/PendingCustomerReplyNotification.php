<?php

namespace App\Notifications;

use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingCustomerReplyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->contactName();
        $channel = ucfirst((string) ($this->conversation->channelAccount?->channel ?: 'inbox'));
        $snippet = trim((string) $this->message->body);

        return (new MailMessage)
            ->subject("Customer waiting for a reply: {$name}")
            ->greeting("A customer is waiting, {$notifiable->name}.")
            ->line("{$name} sent a {$channel} message more than one hour ago and has not received a reply.")
            ->when($snippet !== '', fn (MailMessage $mail) => $mail->line('Message: “'.mb_strimwidth($snippet, 0, 180, '…').'”'))
            ->line('A quick response now can prevent frustration and keep the conversation moving.')
            ->action('Reply to customer', route('client.inbox.show', $this->conversation))
            ->line('This reminder is sent once per unanswered customer message.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pending_customer_reply',
            'conversation_id' => $this->conversation->id,
            'contact_name' => $this->contactName(),
            'snippet' => mb_substr((string) $this->message->body, 0, 120),
            'url' => route('client.inbox.show', $this->conversation),
            'message' => $this->contactName().' has been waiting for a reply for over one hour.',
        ];
    }

    private function contactName(): string
    {
        $contact = $this->conversation->contact;
        $name = trim(implode(' ', array_filter([$contact?->first_name, $contact?->last_name])));

        return $name ?: ($contact?->phone_e164 ?: 'A customer');
    }
}
