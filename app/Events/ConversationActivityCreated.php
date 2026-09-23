<?php

namespace App\Events;

use App\Modules\Shared\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationActivityCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversation.{$this->message->conversation_id}")];
    }

    public function broadcastAs(): string
    {
        return 'ConversationActivityCreated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['message' => $this->message->only([
            'id', 'conversation_id', 'direction', 'channel', 'type', 'body',
            'payload', 'status', 'sent_by', 'user_id', 'sent_at', 'created_at',
        ])];
    }
}
