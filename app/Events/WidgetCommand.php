<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WidgetCommand implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $conversationId,
        /** @var array<string, mixed> */
        public readonly array $command,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("widget-conversation.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'WidgetCommand';
    }

    public function broadcastWith(): array
    {
        return ['command' => $this->command];
    }
}
