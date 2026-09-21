<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.openai.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gpt-4o-mini',
        private readonly string $embedModel = 'text-embedding-3-small',
        private readonly ?string $organization = null,
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $model = $opts['model'] ?? $this->chatModel;
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];
        if (str_starts_with($model, 'gpt-5')) {
            $payload['max_completion_tokens'] = $opts['max_tokens'] ?? 1024;
        } else {
            $payload['max_tokens'] = $opts['max_tokens'] ?? 1024;
            $payload['temperature'] = $opts['temperature'] ?? 0.7;
        }

        $mode = $opts['structured_mode'] ?? null;
        if ($mode === 'json_schema' && is_array($opts['response_schema'] ?? null)) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => [
                'name' => $opts['response_schema']['name'],
                'strict' => true,
                'schema' => $opts['response_schema']['schema'],
            ]];
        } elseif ($mode === 'json_object') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $resp = Http::withHeaders($headers)->retry(2, 500, $this->retryableFailure())->timeout(60)->post(self::BASE.'/chat/completions', $payload);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);

        return new LlmResponse(
            content: $json['choices'][0]['message']['content'] ?? '',
            promptTokens: $json['usage']['prompt_tokens'] ?? 0,
            completionTokens: $json['usage']['completion_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
            structuredMode: $mode,
        );
    }

    public function embed(array $texts): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $resp = Http::withHeaders($headers)->retry(2, 500)->timeout(30)->post(self::BASE.'/embeddings', [
            'model' => $this->embedModel,
            'input' => $texts,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI embed failed: '.$resp->body());
        }

        return array_column($resp->json()['data'] ?? [], 'embedding');
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
