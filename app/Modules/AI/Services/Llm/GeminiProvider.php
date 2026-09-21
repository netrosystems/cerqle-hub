<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements LlmProviderInterface
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gemini-3.5-flash',
        private readonly string $embedModel = 'gemini-embedding-2',
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $model = $opts['model'] ?? $this->chatModel;

        // Extract system instruction separately; remaining turns mapped to user/model
        $systemInstruction = null;
        $contents = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemInstruction = ['parts' => [['text' => $m['content']]]];
            } else {
                $contents[] = [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ];
            }
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $opts['max_tokens'] ?? 1024],
        ];
        if (array_key_exists('temperature', $opts)) {
            $body['generationConfig']['temperature'] = $opts['temperature'];
        }
        $mode = $opts['structured_mode'] ?? null;
        if ($mode === 'json_schema' && is_array($opts['response_schema']['schema'] ?? null)) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = self::toOpenApiSchema($opts['response_schema']['schema']);
        } elseif ($mode === 'json_object') {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }
        if ($systemInstruction) {
            $body['systemInstruction'] = $systemInstruction;
        }

        $resp = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->retry(2, 500, $this->retryableFailure())->timeout(60)
            ->post(self::BASE."/models/{$model}:generateContent", $body);

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $content = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $meta = $json['usageMetadata'] ?? [];

        return new LlmResponse(
            content: $content,
            promptTokens: $meta['promptTokenCount'] ?? 0,
            completionTokens: $meta['candidatesTokenCount'] ?? 0,
            model: $model,
            latencyMs: $latency,
            structuredMode: $mode,
        );
    }

    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $requests = array_map(fn ($text) => [
            'model' => 'models/'.$this->embedModel,
            'content' => ['parts' => [['text' => $text]]],
        ], $texts);

        $resp = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->retry(2, 500)->timeout(60)->post(
                self::BASE."/models/{$this->embedModel}:batchEmbedContents",
                ['requests' => $requests]
            );

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini batch embed failed: '.$resp->body());
        }

        return array_map(
            fn ($e) => $e['values'] ?? [],
            $resp->json('embeddings', [])
        );
    }

    /**
     * Gemini accepts an OpenAPI subset and rejects the whole request when it
     * meets a JSON Schema keyword it does not know, so anything outside that
     * subset is stripped rather than passed through.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function toOpenApiSchema(array $schema): array
    {
        $allowed = ['type', 'format', 'description', 'nullable', 'enum', 'items', 'properties', 'required'];
        $translated = [];

        foreach ($schema as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }
            if ($key === 'properties' && is_array($value)) {
                $translated[$key] = array_map(
                    static fn ($property) => is_array($property) ? self::toOpenApiSchema($property) : $property,
                    $value,
                );

                continue;
            }
            if ($key === 'items' && is_array($value)) {
                $translated[$key] = self::toOpenApiSchema($value);

                continue;
            }
            $translated[$key] = $value;
        }

        return $translated;
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
