<?php

namespace App\Modules\AI\Services\Smart;

use Illuminate\Support\Facades\Cache;

/**
 * Recognises conversational moves in any language, without word lists.
 *
 * The embedding model is multilingual, so a handful of English exemplars recall
 * their equivalents in languages nobody enumerated — including ones a customer
 * invents by mixing scripts. That is the whole mechanism: seeds, not
 * translations, and no locale list anywhere.
 *
 * It reuses the vector retrieval already computes, so recognising "thanks" in
 * an unfamiliar language costs no extra provider call and no credit.
 */
class IntentClassifier
{
    /** Intents that are only ever short. A long message is a question. */
    private const SHORT_ONLY = ['greeting', 'thanks', 'closing', 'decline', 'acceptance'];

    /** Intents that only mean something in reply to a question the bot asked. */
    private const NEEDS_OFFER = ['decline', 'acceptance'];

    public function __construct(private QueryEmbedder $embedder) {}

    /**
     * @param  list<float>  $queryVector  The message vector, already computed for retrieval.
     * @return array{intent:?string,score:float,method:string}
     */
    public function classify(int $workspaceId, string $message, array $queryVector, bool $botAskedAQuestion = false): array
    {
        if (! config('ai.smart_bot.intent.enabled')) {
            return $this->none('disabled');
        }
        $message = trim($message);
        if ($message === '') {
            return $this->none('empty');
        }
        if ($queryVector === []) {
            // No embedding provider: the caller falls back to its existing
            // English matching, so behaviour degrades rather than breaking.
            return $this->none('unavailable');
        }

        $exemplars = $this->exemplarVectors($workspaceId);
        if ($exemplars === []) {
            return $this->none('unavailable');
        }

        $short = $this->looksConversational($message);
        $scores = [];
        foreach ($exemplars as $intent => $vectors) {
            if (in_array($intent, self::SHORT_ONLY, true) && ! $short) {
                continue;
            }
            if (in_array($intent, self::NEEDS_OFFER, true) && ! $botAskedAQuestion) {
                continue;
            }
            // Max over exemplars, not an average: acceptance and decline are
            // near-antonyms, and a centroid blurs exactly that distinction.
            $best = 0.0;
            foreach ($vectors as $vector) {
                $best = max($best, $this->cosine($queryVector, $vector));
            }
            $scores[$intent] = $best;
        }
        if ($scores === []) {
            return $this->none('guarded');
        }

        arsort($scores);
        $intent = (string) array_key_first($scores);
        $score = (float) $scores[$intent];
        $runnerUp = (float) (array_values($scores)[1] ?? 0.0);

        $threshold = $intent === 'wants_human'
            ? (float) config('ai.smart_bot.intent.human_threshold', 0.82)
            : (float) config('ai.smart_bot.intent.threshold', 0.62);

        if ($score < $threshold || ($score - $runnerUp) < (float) config('ai.smart_bot.intent.margin', 0.04)) {
            return ['intent' => null, 'score' => $score, 'method' => 'embedding'];
        }

        return ['intent' => $intent, 'score' => $score, 'method' => 'embedding'];
    }

    /**
     * Chit-chat is short, has no figures and rarely asks a question. These
     * guards run before any similarity is computed, so a real question is never
     * answered with "you're welcome" however much it resembles one.
     */
    private function looksConversational(string $message): bool
    {
        if (mb_strlen($message) > (int) config('ai.smart_bot.intent.max_chars', 48)) {
            return false;
        }
        if (count(preg_split('/\s+/u', $message) ?: []) > (int) config('ai.smart_bot.intent.max_words', 6)) {
            return false;
        }
        // A question mark in anything but the briefest message means a request.
        if (mb_strlen($message) > 15 && preg_match('/[?؟？]/u', $message)) {
            return false;
        }

        // Figures and currency belong to business questions, never to chit-chat.
        return ! preg_match('/\p{Sc}|\d{2,}/u', $message);
    }

    /**
     * Exemplar vectors, embedded once and shared by every workspace.
     *
     * The exemplars are Cerqle-authored English constants, so nothing derived
     * from a tenant's data is cached here.
     *
     * @return array<string, list<list<float>>>
     */
    private function exemplarVectors(int $workspaceId): array
    {
        $model = (string) config('ai.managed.embedding_model', 'text-embedding-3-small');
        $key = 'ai:intent:v'.config('ai.smart_bot.intent.version', 1).':'.$model;

        try {
            $cached = Cache::get($key);
            if (is_array($cached) && $cached !== []) {
                return $cached;
            }
        } catch (\Throwable) {
            // Fall through and re-embed; a cache miss is only a cost, not a bug.
        }

        $vectors = [];
        foreach ((array) config('ai.smart_bot.intent.exemplars', []) as $intent => $phrases) {
            foreach ((array) $phrases as $phrase) {
                $vector = $this->embedder->vector($workspaceId, (string) $phrase);
                if ($vector !== []) {
                    $vectors[$intent][] = $vector;
                }
            }
        }
        if ($vectors === []) {
            return [];
        }

        try {
            Cache::put($key, $vectors, now()->addDays(30));
        } catch (\Throwable) {
            // Optimisation only.
        }

        return $vectors;
    }

    /** @return array{intent:null,score:float,method:string} */
    private function none(string $method): array
    {
        return ['intent' => null, 'score' => 0.0, 'method' => $method];
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $length = min(count($a), count($b));
        for ($i = 0; $i < $length; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        return $normA > 0 && $normB > 0 ? $dot / (sqrt($normA) * sqrt($normB)) : 0.0;
    }
}
