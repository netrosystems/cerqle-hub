<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\AI\Models\AiKbChunk;

/**
 * Decides which retrieved passages an answer is allowed to rely on.
 *
 * Retrieval itself stays in EmbeddingStore, which already returns semantic,
 * lexical and fuzzy sub-scores per result and has them discarded by the caller.
 * This consumes them: re-ranking, source authority, near-duplicate removal and
 * a context budget all happen here, so the prompt receives the strongest
 * evidence rather than merely the first five rows.
 */
class EvidenceSelector
{
    /**
     * @param  array<int, array<string,mixed>>  $results
     * @return array{passages: array<int, array<string,mixed>>, best_score: float, dropped: int, chars: int}
     */
    public function select(array $results, int $maxPassages): array
    {
        if ($results === []) {
            return ['passages' => [], 'best_score' => 0.0, 'dropped' => 0, 'chars' => 0];
        }

        // Every step here changes what the model is shown, so all of them wait
        // on the same switch: with it off this returns exactly what retrieval
        // returned, and existing bots are unaffected.
        if (! config('ai.smart_bot.retrieval_authority')) {
            return [
                'passages' => $results,
                'best_score' => (float) ($results[0]['score'] ?? 0.0),
                'dropped' => 0,
                'chars' => array_sum(array_map(fn (array $r) => mb_strlen($this->content($r)), $results)),
            ];
        }

        $ranked = $this->rank($results);
        $deduplicated = $this->dropNearDuplicates($ranked);
        $dropped = count($ranked) - count($deduplicated);
        $budgeted = $this->applyBudget($deduplicated, $maxPassages);

        return [
            'passages' => $budgeted,
            'best_score' => (float) ($budgeted[0]['score'] ?? $deduplicated[0]['score'] ?? 0.0),
            'dropped' => $dropped,
            'chars' => array_sum(array_map(fn (array $r) => mb_strlen($this->content($r)), $budgeted)),
        ];
    }

    /**
     * Typos and exact wording each help a little and neither is allowed to
     * dominate the semantic score. Source authority is how a document the client
     * maintains outranks a public page that still says the opposite.
     *
     * @param  array<int, array<string,mixed>>  $results
     * @return array<int, array<string,mixed>>
     */
    private function rank(array $results): array
    {
        foreach ($results as $index => $result) {
            $semantic = (float) ($result['semantic_score'] ?? $result['score'] ?? 0.0);
            $lexical = (float) ($result['lexical_score'] ?? 0.0);
            $fuzzy = (float) ($result['fuzzy_score'] ?? 0.0);

            $results[$index]['score'] = $semantic
                + min(0.18, $lexical * 0.12 + $fuzzy * 0.06)
                + $this->authorityWeight($result);
        }

        usort($results, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $results;
    }

    /** @param array<string,mixed> $result */
    private function authorityWeight(array $result): float
    {
        $document = ($result['chunk'] ?? null) instanceof AiKbChunk ? $result['chunk']->document : null;
        if (! $document) {
            return 0.0;
        }

        $priority = (int) ($document->priority ?? 50);

        return ($document->authoritative ? 0.06 : 0.0) + (($priority - 50) / 50) * 0.04;
    }

    /**
     * A sitemap crawl repeats navigation and boilerplate across dozens of pages.
     * Left alone, those duplicates fill the context window and crowd out the one
     * passage that answers the question.
     *
     * @param  array<int, array<string,mixed>>  $results
     * @return array<int, array<string,mixed>>
     */
    private function dropNearDuplicates(array $results): array
    {
        $threshold = (float) config('ai.smart_bot.duplicate_overlap', 0.75);
        $kept = [];
        $keptTokens = [];

        foreach ($results as $result) {
            $tokens = $this->wordSet($this->content($result));
            if ($tokens === []) {
                continue;
            }
            foreach ($keptTokens as $existing) {
                if ($this->jaccard($tokens, $existing) >= $threshold) {
                    continue 2;
                }
            }
            $kept[] = $result;
            $keptTokens[] = $tokens;
        }

        return $kept;
    }

    /**
     * Caps how much evidence reaches the prompt. Without this a chunk of up to
     * 6000 characters times max_context_chunks goes verbatim to the model.
     *
     * @param  array<int, array<string,mixed>>  $results
     * @return array<int, array<string,mixed>>
     */
    private function applyBudget(array $results, int $maxPassages): array
    {
        $totalBudget = (int) config('ai.smart_bot.context_chars', 6000);
        $passageBudget = (int) config('ai.smart_bot.passage_chars', 1800);
        $kept = [];
        $used = 0;

        foreach (array_slice($results, 0, max(1, $maxPassages)) as $result) {
            $content = $this->content($result);
            if ($content === '') {
                continue;
            }
            if (mb_strlen($content) > $passageBudget) {
                $content = $this->truncate($content, $passageBudget);
                $result['chunk']->content = $content;
            }
            // Always keep at least one passage: an over-long single passage is
            // better trimmed than dropped, which would look like "no evidence".
            if ($kept !== [] && $used + mb_strlen($content) > $totalBudget) {
                break;
            }
            $used += mb_strlen($content);
            $kept[] = $result;
        }

        return $kept;
    }

    /** Cuts on a sentence boundary where one is available, so the tail is not a fragment. */
    private function truncate(string $content, int $limit): string
    {
        $slice = mb_substr($content, 0, $limit);
        if (preg_match('/^(.*[.!?۔。！？])\s/su', $slice.' ', $matches)) {
            return trim($matches[1]);
        }

        return rtrim($slice).'…';
    }

    /** @param array<string,mixed> $result */
    private function content(array $result): string
    {
        return ($result['chunk'] ?? null) instanceof AiKbChunk ? (string) $result['chunk']->content : '';
    }

    /** @return list<string> */
    private function wordSet(string $text): array
    {
        // The same tokenisation the runner uses for relevance, so every script
        // splits identically.
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }
}
