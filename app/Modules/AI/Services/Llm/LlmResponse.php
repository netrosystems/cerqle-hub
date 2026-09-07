<?php

namespace App\Modules\AI\Services\Llm;

class LlmResponse
{
    /** @param array<string, mixed> $payload */
    public static function fromStoredResult(array $payload, int $creditUsageId): self
    {
        if (! is_string($payload['content'] ?? null)
            || ! is_int($payload['promptTokens'] ?? null)
            || ! is_int($payload['completionTokens'] ?? null)
            || ! is_string($payload['model'] ?? null)
            || ! is_int($payload['latencyMs'] ?? null)) {
            throw new \UnexpectedValueException('Stored AI result is incomplete or invalid.');
        }

        return new self(
            content: $payload['content'],
            promptTokens: $payload['promptTokens'],
            completionTokens: $payload['completionTokens'],
            model: $payload['model'],
            latencyMs: $payload['latencyMs'],
            creditUsageId: $creditUsageId,
        );
    }

    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
        public readonly int $latencyMs,
        public readonly ?int $creditUsageId = null,
    ) {}
}
