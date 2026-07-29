<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Notifications\ConversationHandoverNotification;

class ConversationHandoverService
{
    /**
     * Move a bot conversation to the human queue and notify the workspace once.
     *
     * @return bool Whether this call created a new handover.
     */
    public function request(Conversation $conversation, string $reason = 'user_request'): bool
    {
        $created = false;

        $conversation->getConnection()->transaction(function () use ($conversation, &$created): void {
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);

            if ($locked->assigned_to === 'human' && $locked->handover_at !== null) {
                $conversation->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $locked->update([
                'assigned_to' => 'human',
                'handover_at' => $locked->handover_at ?: now(),
            ]);
            $conversation->setRawAttributes($locked->fresh()->getAttributes(), true);
            $created = true;
        });

        if ($created) {
            User::where('workspace_id', $conversation->workspace_id)
                ->each(fn (User $member) => $member->notify(
                    new ConversationHandoverNotification($conversation, $reason)
                ));
        }

        return $created;
    }
}
