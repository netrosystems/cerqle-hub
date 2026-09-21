<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
        $mode = in_array($opts['structured_mode'] ?? null, ['json_schema', 'json_object'], true)
            ? 'json_object'
            : ($opts['structured_mode'] ?? null);
        $body = [
            'model' => $opts['model'] ?? $this->chatModel,
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'temperature' => $opts['temperature'] ?? 0.7,
        ];
        if ($mode === 'json_object') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->retry(2, 500, $this->retryableFailure())
            ->timeout(120)
            ->post(self::BASE.'/chat/completions', $body);

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
            structuredMode: $mode,
        );
    }

    public function embed(array $texts): array
    {
        throw new \RuntimeException(
            'DeepSeek does not provide embeddings. Configure an OpenAI or Gemini embedding provider.'
        );
    }

    /**
     * A 4xx will not change on a retry, so only transport failures, rate limits
     * and server errors are worth sending again. Retrying a rejected request
     * would double the cost of discovering a provider limitation.
     */
    private function retryableFailure(): \Closure
    {
        return static function (\Throwable $exception): bool {
            if ($exception instanceof ConnectionException) {
                return true;
            }
            $status = $exception instanceof RequestException
                ? $exception->response->status()
                : 0;

            return $status === 429 || $status >= 500;
        };
    }
}
