<?php

namespace App\Events;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationAssigned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly ?User $assignedTo,
    ) {}

    public function broadcastOn(): array
    {
        $wsId = $this->conversation->workspace_id;
        $convId = $this->conversation->id;

        return [
            new PrivateChannel("workspace.{$wsId}"),
            new PrivateChannel("conversation.{$convId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationAssigned';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->id,
            'mode' => $this->conversation->assigned_to ?? 'bot',
            'handover_at' => $this->conversation->handover_at?->toIso8601String(),
            'assigned_to' => $this->assignedTo ? [
                'id' => $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null,
        ];
    }
}
