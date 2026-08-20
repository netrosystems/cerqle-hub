<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetTypingChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        public readonly bool $isTyping,
        public readonly ?string $name,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("widget-conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'WidgetTypingChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'agent_typing' => [
                'is_typing' => $this->isTyping,
                'name' => $this->name,
            ],
        ];
    }
}
