<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\AI\Services\LlmGateway;
use Illuminate\Support\Facades\Cache;

/**
 * One vector per customer message, computed once and shared.
 *
 * Retrieval, intent classification, choice matching and the semantic cache all
 * need the same embedding. Computing it here means adding language-agnostic
 * behaviour costs no extra provider calls.
 *
 * Embeddings are free: LlmGateway::embed() records an AiRun against the
 * infrastructure provider and never opens a credit reservation.
 */
class QueryEmbedder
{
    public function __construct(private LlmGateway $llmGateway) {}

    /**
     * The message vector, or an empty array when no embedding provider is
     * configured or the provider failed.
     *
     * This never throws. A caller that genuinely needs the failure — the
     * playground, which reports provider errors to the operator — passes
     * $throwProviderErrors.
     *
     * @return list<float>
     */
    public function vector(int $workspaceId, string $text, bool $throwProviderErrors = false): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $key = $this->key($workspaceId, $text);
        $cached = $this->remembered($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $embedding = $this->llmGateway->embed($workspaceId, [$text])[0] ?? [];
        } catch (\Throwable $error) {
            if ($throwProviderErrors) {
                throw $error;
            }

            return [];
        }

        if ($embedding !== []) {
            $this->store($key, $embedding);
        }

        return $embedding;
    }

    /**
     * Vectors for many texts in one provider round trip, keyed by the text.
     *
     * Embedding a list one item at a time is a round trip each: the intent
     * exemplars alone are about fifty, which took twenty seconds and fell on
     * whichever customer happened to say hello first after a cache flush.
     * Providers accept a batch, so this asks once.
     *
     * @param  list<string>  $texts
     * @return array<string, list<float>>
     */
    public function vectors(int $workspaceId, array $texts): array
    {
        $wanted = [];
        $found = [];
        foreach ($texts as $text) {
            $text = trim($text);
            if ($text === '' || isset($found[$text]) || in_array($text, $wanted, true)) {
                continue;
            }
            $cached = $this->remembered($this->key($workspaceId, $text));
            if ($cached !== null) {
                $found[$text] = $cached;

                continue;
            }
            $wanted[] = $text;
        }

        if ($wanted === []) {
            return $found;
        }

        try {
            $embeddings = $this->llmGateway->embed($workspaceId, $wanted);
        } catch (\Throwable) {
            // The caller degrades to its own fallback; a missing vector is
            // never worth failing a customer's reply over.
            return $found;
        }

        foreach ($wanted as $index => $text) {
            $embedding = $embeddings[$index] ?? [];
            if (is_array($embedding) && $embedding !== []) {
                $this->store($this->key($workspaceId, $text), $embedding);
                $found[$text] = $embedding;
            }
        }

        return $found;
    }

    private function key(int $workspaceId, string $text): string
    {
        // Workspace-scoped: the input is customer text, so it never crosses a
        // tenant boundary. The model is part of the key because vectors from
        // different embedding models are not comparable.
        $model = (string) config('ai.managed.embedding_model', 'text-embedding-3-small');

        return 'ai:qemb:w'.$workspaceId.':'.$model.':'.sha1($text);
    }

    /** @return list<float>|null */
    private function remembered(string $key): ?array
    {
        try {
            $cached = Cache::get($key);

            return is_array($cached) && $cached !== [] ? $cached : null;
        } catch (\Throwable) {
            // A cache that cannot be read is still a working bot.
            return null;
        }
    }

    /** @param list<float> $embedding */
    private function store(string $key, array $embedding): void
    {
        try {
            Cache::put($key, $embedding, now()->addMinutes(
                (int) config('ai.smart_bot.query_embedding_ttl_minutes', 10080)
            ));
        } catch (\Throwable) {
            // Caching is an optimisation, never a precondition.
        }
    }
}
