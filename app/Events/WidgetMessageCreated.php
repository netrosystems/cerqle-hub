<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        /** @var array<string, mixed> */
        public readonly array $message,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("widget-conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'WidgetMessageCreated';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
