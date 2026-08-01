<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\Request;
use RuntimeException;

class EmailDriver implements ChannelDriverInterface
{
    public function __construct(
        private readonly MicrosoftGraphMailClient $microsoft,
        private readonly GenericMailboxClient $generic,
    ) {}

    public function send(Message $message): string
    {
        $message->loadMissing('conversation.channelAccount', 'conversation.contact');
        $conversation = $message->conversation;
        $account = $conversation?->channelAccount;
        if (! $account || ! $conversation?->contact?->email) {
            throw new RuntimeException('This email conversation has no connected mailbox or recipient address.');
        }
        $inbound = $conversation->messages()->where('direction', 'in')->latest('id')->first();
        $subject = (string) ($inbound?->payload['subject'] ?? 'Re: Message');
        if (! str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: '.$subject;
        }

        return $account->provider === 'microsoft_365'
            ? $this->microsoft->sendReply($account, (string) $inbound?->provider_message_id, (string) $message->body)
            : $this->generic->send(
                $account,
                $conversation->contact->email,
                $subject,
                (string) $message->body,
                $inbound?->payload['internet_message_id'] ?? null,
            );
    }

    public function receiveWebhook(Request $request): array
    {
        return [];
    }

    public function verifyCreds(): bool
    {
        return false;
    }
}
