<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\AI\ValueObjects\ChatbotAnswer;

/**
 * Makes the bot's choices visible on channels that have no buttons.
 *
 * Only the web widget renders quick replies. On WhatsApp, Messenger, Instagram
 * and email they are silently dropped, so a reply offering three options arrives
 * as a bare question with no affordance at all — and the customer's free-text
 * answer then has nothing to match against.
 *
 * Appending a numbered list gives them something to answer, and ChoiceResolver
 * reads "2" back in any digit system.
 */
class ChoiceRenderer
{
    /**
     * The body to send on this channel.
     *
     * Returns the body unchanged for a null answer, which is what a mocked
     * runner produces in tests, and for channels that render buttons natively.
     */
    public function body(?ChatbotAnswer $answer, string $channel, string $fallbackBody): string
    {
        if (! config('ai.smart_bot.channel_choice_fallback') || ! $answer) {
            return $fallbackBody;
        }

        $body = trim((string) ($answer->displayBody ?? $fallbackBody));
        $channels = (array) config('ai.smart_bot.choice_channels', []);
        $capabilities = $channels[$channel] ?? ['native' => false, 'max' => 3];

        if (($capabilities['native'] ?? false) === true) {
            return $body;
        }

        $choices = Choices::normalise($answer->quickReplies, $answer->handoffOffer);
        if ($choices === []) {
            return $body;
        }

        // Capped at three: portable across channels and matching WhatsApp's own
        // interactive-button ceiling, so the same text works everywhere.
        $lines = [];
        foreach (array_slice($choices, 0, (int) ($capabilities['max'] ?? 3)) as $index => $choice) {
            $lines[] = ($index + 1).'. '.$choice['label'];
        }

        return $body."\n\n".implode("\n", $lines);
    }
}
