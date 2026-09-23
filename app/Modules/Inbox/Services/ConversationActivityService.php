<?php

namespace App\Modules\Inbox\Services;

use App\Events\ConversationActivityCreated;
use App\Events\ConversationOwnershipChanged;
use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationActivityService
{
    public function __construct(private readonly TeamAvailabilityService $availability) {}

    public function join(Conversation $conversation, User $actor): Conversation
    {
        return $this->change($conversation, $actor, function (Conversation $locked) use ($actor): ?array {
            if ($locked->status === 'resolved') {
                throw new ConversationOwnershipException('Reopen this chat before joining.');
            }
            if ($locked->joined_user_id && (int) $locked->joined_user_id !== (int) $actor->id) {
                $locked->loadMissing('joinedUser');
                throw new ConversationOwnershipException('Another agent has joined this chat.', 409, [
                    'joined_user' => $this->publicUser($locked->joinedUser),
                ]);
            }
            if ($locked->joined_user_id) {
                return null;
            }

            $locked->update([
                'assigned_user_id' => $actor->id,
                'joined_user_id' => $actor->id,
                'joined_at' => now(),
                'assigned_to' => 'human',
                'status' => 'open',
                'resolved_at' => null,
            ]);

            return ['conversation.joined', "{$actor->name} joined the chat"];
        });
    }

    public function takeover(Conversation $conversation, User $actor): Conversation
    {
        return $this->change($conversation, $actor, function (Conversation $locked) use ($actor): ?array {
            if ($locked->status === 'resolved') {
                throw new ConversationOwnershipException('Reopen this chat before taking over.');
            }
            if (! $locked->joined_user_id) {
                $locked->update(['assigned_user_id' => $actor->id, 'joined_user_id' => $actor->id, 'joined_at' => now(), 'assigned_to' => 'human', 'status' => 'open', 'resolved_at' => null]);

                return ['conversation.joined', "{$actor->name} joined the chat"];
            }
            if ((int) $locked->joined_user_id === (int) $actor->id) {
                return null;
            }
            if (! $actor->isClientAdministrator()) {
                if (! $this->availability->isAvailable((int) $locked->workspace_id, $actor)) {
                    throw new ConversationOwnershipException('You must be currently available to take over this chat.', 403, [
                        'joined_user' => $this->publicUser($locked->joinedUser),
                    ]);
                }
                if ($this->availability->isAvailable((int) $locked->workspace_id, (int) $locked->joined_user_id)) {
                    throw new ConversationOwnershipException('The joined agent is still available. Ask them or an administrator to transfer the chat.', 403, [
                        'joined_user' => $this->publicUser($locked->joinedUser),
                    ]);
                }
            }
            $previous = $locked->joinedUser;
            $locked->update(['assigned_user_id' => $actor->id, 'joined_user_id' => $actor->id, 'joined_at' => now(), 'assigned_to' => 'human', 'status' => 'open', 'resolved_at' => null]);

            return ['conversation.transferred', "{$actor->name} took over the chat", ['previous_actor' => $previous ? $this->snapshot($previous) : null]];
        });
    }

    public function leave(Conversation $conversation, User $actor): Conversation
    {
        return $this->change($conversation, $actor, function (Conversation $locked) use ($actor): ?array {
            if (! $locked->joined_user_id) {
                return null;
            }
            if ((int) $locked->joined_user_id !== (int) $actor->id && ! $actor->isClientAdministrator()) {
                throw new ConversationOwnershipException('Only the joined agent or an administrator can leave this chat.', 403);
            }
            $previous = $locked->joinedUser;
            $updates = ['joined_user_id' => null, 'joined_at' => null];
            if ((int) $locked->assigned_user_id === (int) $locked->joined_user_id) {
                $updates['assigned_user_id'] = null;
            }
            $locked->update($updates);

            $body = $previous && (int) $previous->id !== (int) $actor->id
                ? "{$actor->name} removed {$previous->name} from the chat"
                : "{$actor->name} left the chat";

            return ['conversation.left', $body, ['subject' => $previous ? $this->snapshot($previous) : null]];
        });
    }

    public function assign(Conversation $conversation, ?User $assignee, User $actor): Conversation
    {
        return $this->change($conversation, $actor, function (Conversation $locked) use ($assignee, $actor): ?array {
            if ($assignee) {
                abort_unless(User::inWorkspace($locked->workspace_id)->whereKey($assignee->id)->exists(), 422);
            }
            if ((int) $locked->assigned_user_id === (int) $assignee?->id) {
                return null;
            }
            $updates = ['assigned_user_id' => $assignee?->id];
            if ((int) $locked->joined_user_id !== (int) $assignee?->id) {
                $updates += ['joined_user_id' => null, 'joined_at' => null];
            }
            $locked->update($updates);

            return $assignee
                ? ['conversation.assigned', "{$actor->name} assigned the chat to {$assignee->name}", ['subject' => $this->snapshot($assignee)]]
                : ['conversation.unassigned', "{$actor->name} unassigned the chat"];
        });
    }

    public function status(Conversation $conversation, string $status, User $actor): Conversation
    {
        abort_unless(in_array($status, ['open', 'pending', 'resolved', 'snoozed'], true), 422);

        return $this->change($conversation, $actor, function (Conversation $locked) use ($status, $actor): ?array {
            if ($locked->status === $status) {
                return null;
            }
            $previous = $locked->status;
            $updates = ['status' => $status, 'resolved_at' => $status === 'resolved' ? ($locked->resolved_at ?? now()) : null];
            if ($status === 'resolved') {
                $updates += [
                    'assigned_user_id' => null,
                    'joined_user_id' => null,
                    'joined_at' => null,
                    'handover_at' => null,
                ];
            }
            $locked->update($updates);

            $type = match ($status) {
                'resolved' => 'conversation.resolved',
                'pending' => 'conversation.pending',
                'snoozed' => 'conversation.snoozed',
                default => 'conversation.reopened',
            };
            $body = match ($status) {
                'resolved' => "Resolved by {$actor->name}",
                'pending' => "{$actor->name} marked the chat as pending",
                'snoozed' => "{$actor->name} snoozed the chat",
                default => "{$actor->name} reopened the chat",
            };

            return [$type, $body, ['previous_status' => $previous]];
        });
    }

    public function assertCanReply(Conversation $conversation, User $actor): void
    {
        if ((int) $conversation->joined_user_id !== (int) $actor->id) {
            $conversation->loadMissing('joinedUser');
            throw new ConversationOwnershipException('Join this chat before replying.', 409, [
                'joined_user' => $this->publicUser($conversation->joinedUser),
            ]);
        }
    }

    public function canTakeover(Conversation $conversation, User $actor): bool
    {
        if ($conversation->status === 'resolved' || ! $conversation->joined_user_id || (int) $conversation->joined_user_id === (int) $actor->id) {
            return false;
        }
        if ($actor->isClientAdministrator()) {
            return true;
        }

        return $this->availability->isAvailable((int) $conversation->workspace_id, $actor)
            && ! $this->availability->isAvailable((int) $conversation->workspace_id, (int) $conversation->joined_user_id);
    }

    /** @param callable(Conversation): (array<int|string, mixed>|null) $transition */
    private function change(Conversation $conversation, User $actor, callable $transition): Conversation
    {
        abort_unless(User::inWorkspace($conversation->workspace_id)->whereKey($actor->id)->exists(), 403);

        return $this->synchronized($conversation, fn () => DB::transaction(function () use ($conversation, $actor, $transition): Conversation {
            $locked = Conversation::query()
                ->where('workspace_id', $conversation->workspace_id)
                ->lockForUpdate()
                ->findOrFail($conversation->id);
            $result = $transition($locked);
            if ($result) {
                [$type, $body] = $result;
                $payload = ['type' => $type, 'actor' => $this->snapshot($actor)] + ($result[2] ?? []);
                $activity = $this->activity($locked, $actor, $body, $payload);
                $updated = $locked->fresh(['joinedUser', 'channelAccount']);
                DB::afterCommit(function () use ($activity, $updated): void {
                    ConversationActivityCreated::dispatch($activity);
                    ConversationOwnershipChanged::dispatch($updated);
                });

                return $updated;
            }

            return $locked->fresh(['joinedUser', 'channelAccount']);
        }));
    }

    /** @param array<string, mixed> $activity */
    private function activity(Conversation $conversation, User $actor, string $body, array $activity): Message
    {
        return $conversation->messages()->create([
            'direction' => 'system',
            'channel' => $conversation->channelAccount()->value('channel') ?? 'system',
            'type' => 'event',
            'body' => $body,
            'payload' => ['activity' => $activity],
            'status' => 'delivered',
            'sent_by' => 'system',
            'user_id' => $actor->id,
            'sent_at' => now(),
        ]);
    }

    /** @return array{id:int,name:string} */
    private function snapshot(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name];
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

    public function synchronized(Conversation $conversation, callable $callback): mixed
    {
        return Cache::lock('conversation-ai-reply:'.$conversation->id, 150)->block(30, $callback);
    }
}
