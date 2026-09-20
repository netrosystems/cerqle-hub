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
