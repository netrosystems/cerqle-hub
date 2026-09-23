<?php

namespace App\Events;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationOwnershipChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Conversation $conversation) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->conversation->workspace_id}"),
            new PrivateChannel("conversation.{$this->conversation->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ConversationOwnershipChanged';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $this->conversation->loadMissing('joinedUser');

        return [
            'conversation_id' => $this->conversation->id,
            'status' => $this->conversation->status,
            'assigned_to' => $this->conversation->assigned_to,
            'assigned_user_id' => $this->conversation->assigned_user_id,
            'joined_at' => $this->conversation->joined_at?->toIso8601String(),
            'joined_user' => $this->publicUser($this->conversation->joinedUser),
        ];
    }

    /** @return array{id:int,name:string,avatar:mixed,avatar_url:string}|null */
    private function publicUser(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar ?? null,
            'avatar_url' => $user->avatarUrl(),
        ] : null;
    }
}
