<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * Ephemeral typing state shared by the authenticated inbox and public widget.
 *
 * Presence expires automatically, so a closed tab or interrupted request can
 * never leave a permanent "Typing..." indicator. No typing event is persisted
 * to the database.
 */
class TypingPresence
{
    private const TTL_SECONDS = 6;

    public function setAgent(Conversation $conversation, User $user, bool $isTyping): void
    {
        $key = $this->agentKey($conversation);

        if (! $isTyping) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, [
            'user_id' => $user->id,
            'user_name' => $user->name,
        ], now()->addSeconds(self::TTL_SECONDS));
    }

    public function setVisitor(Conversation $conversation, bool $isTyping): void
    {
        $key = $this->visitorKey($conversation);

        if (! $isTyping) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, true, now()->addSeconds(self::TTL_SECONDS));
    }

    /**
     * @return array{user_id:int,user_name:string}|null
     */
    public function agent(Conversation $conversation): ?array
    {
        $presence = Cache::get($this->agentKey($conversation));

        return is_array($presence) ? $presence : null;
    }

    public function visitorIsTyping(Conversation $conversation): bool
    {
        return Cache::get($this->visitorKey($conversation)) === true;
    }

    private function agentKey(Conversation $conversation): string
    {
        return "inbox:typing:agent:{$conversation->id}";
    }

    private function visitorKey(Conversation $conversation): string
    {
        return "inbox:typing:visitor:{$conversation->id}";
    }
}
