<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\Shared\Models\Conversation;

/**
 * Remembers what language a conversation is being held in.
 *
 * Nothing in Cerqle detects language, and adding a detector would mean picking
 * the languages it knows about. Instead the model names the language it just
 * replied in — it has already read the message, so naming it is free — and that
 * tag is kept on the conversation. Every later deterministic turn is then a
 * cache hit rather than a guess.
 *
 * Before any model has replied the tag is simply unknown, which is a normal
 * state: CannedPhrases handles 'und' by showing the model the customer's own
 * message, so the very first turn still comes out in the right language.
 */
class ConversationLanguage
{
    public const UNKNOWN = 'und';

    /** The language to answer this turn in. */
    public function resolve(?int $conversationId, ?string $browserHint = null): string
    {
        if ($conversationId === null) {
            return self::UNKNOWN;
        }

        $stored = Conversation::whereKey($conversationId)->value('ai_language');
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        // A browser hint describes the device, not the message: someone abroad
        // on an English phone may well be writing in something else. It is a
        // last resort, used only until the model reports the real language.
        return $this->normalise($browserHint) ?? self::UNKNOWN;
    }

    /** Records the language the model reported, so later free turns can use it. */
    public function remember(?int $conversationId, ?string $language, string $source = 'model'): void
    {
        $tag = $this->normalise($language);
        if ($conversationId === null || $tag === null) {
            return;
        }

        try {
            Conversation::whereKey($conversationId)->update([
                'ai_language' => $tag,
                'ai_language_source' => $source,
            ]);
        } catch (\Throwable) {
            // Remembering is an optimisation; the next turn simply re-learns it.
        }
    }

    /** Accepts BCP-47-shaped tags only, so a model cannot write prose into the column. */
    private function normalise(?string $language): ?string
    {
        $language = trim((string) $language);
        if ($language === '' || strtolower($language) === self::UNKNOWN) {
            return null;
        }
        if (! preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) || mb_strlen($language) > 16) {
            return null;
        }

        return $language;
    }
}
