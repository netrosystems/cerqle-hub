<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class DeepSeekProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.deepseek.com';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'deepseek-v4-flash',
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->retry(2, 500)
            ->timeout(120)
            ->post(self::BASE.'/chat/completions', [
                'model' => $opts['model'] ?? $this->chatModel,
                'messages' => $messages,
                'max_tokens' => $opts['max_tokens'] ?? 1024,
                'temperature' => $opts['temperature'] ?? 0.7,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('DeepSeek chat failed: '.$response->body());
        }

        $payload = $response->json();

        return new LlmResponse(
            content: $payload['choices'][0]['message']['content'] ?? '',
            promptTokens: $payload['usage']['prompt_tokens'] ?? 0,
            completionTokens: $payload['usage']['completion_tokens'] ?? 0,
            model: $payload['model'] ?? $this->chatModel,
            latencyMs: (int) ((microtime(true) - $start) * 1000),
        );
    }

    public function embed(array $texts): array
    {
        throw new \RuntimeException(
            'DeepSeek does not provide embeddings. Configure an OpenAI or Gemini embedding provider.'
        );
    }
}
