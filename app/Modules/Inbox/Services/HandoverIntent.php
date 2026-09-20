<?php

namespace App\Modules\Inbox\Services;

use const App\Listeners\HANDOVER_PHRASES;

use App\Modules\AI\Services\Smart\ChoiceResolver;
use App\Modules\AI\Services\Smart\Choices;
use App\Modules\AI\Services\Smart\IntentClassifier;
use App\Modules\AI\Services\Smart\QueryEmbedder;
use App\Modules\Shared\Models\Message;

/**
 * Does this message ask for a person?
 *
 * Until now the only way to reach a human by typing was in English: eleven
 * English substrings in routing, and a widget that decided a button meant
 * "handoff" if its label contained the word "person". A customer writing in any
 * other language had no route to a human at all.
 *
 * Three tiers, cheapest and most certain first.
 */
class HandoverIntent
{
    public function __construct(
        private ChoiceResolver $choices,
        private IntentClassifier $intents,
        private QueryEmbedder $embedder,
    ) {}

    /**
     * @param  bool  $allowEmbedding  False on the synchronous inbound path.
     *                                AutoReplyListener runs inside the customer's own HTTP request, so a
     *                                provider call there would be latency the customer waits through.
     */
    public function wants(Message $message, int $workspaceId, bool $allowEmbedding = false): bool
    {
        $body = trim((string) ($message->body ?? ''));
        if ($body === '') {
            return false;
        }

        // 1. The existing English list, unchanged and free.
        $lower = mb_strtolower($body);
        foreach (HANDOVER_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        if (! config('ai.smart_bot.multilingual_handover')) {
            return false;
        }

        // 2. The customer picked the handoff choice the bot itself offered.
        // Deterministic, no model, and correct in every language.
        $choice = $this->choices->resolve($message, $body);
        if (($choice['role'] ?? null) === Choices::ROLE_HANDOFF) {
            return true;
        }

        // 3. They asked for a person in their own words.
        if (! $allowEmbedding) {
            return false;
        }

        $vector = $this->embedder->vector($workspaceId, $body);
        $result = $this->intents->classify($workspaceId, $body, $vector);

        return $result['intent'] === 'wants_human';
    }
}
