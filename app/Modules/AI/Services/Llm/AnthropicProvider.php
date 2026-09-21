<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class AnthropicProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.anthropic.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'claude-haiku-4-5-20251001',
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);

        // Anthropic separates the system turn from the conversation turns
        $system = null;
        $turns = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $system = $m['content'];
            } else {
                $turns[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }

        $body = [
            'model' => $opts['model'] ?? $this->chatModel,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'messages' => $turns,
        ];
        if ($system !== null) {
            $body['system'] = $system;
        }
        if (array_key_exists('temperature', $opts)) {
            $body['temperature'] = $opts['temperature'];
        }

        // Anthropic has no response_format. A forced tool call is how a schema
        // is enforced here; JSON mode has no equivalent at all, so it degrades
        // to plain text and the prompt has to carry the contract.
        $mode = ($opts['structured_mode'] ?? null) === 'json_schema' && is_array($opts['response_schema'] ?? null)
            ? 'json_schema'
            : (($opts['structured_mode'] ?? null) === 'json_object' ? 'text' : ($opts['structured_mode'] ?? null));
        $toolName = null;
        if ($mode === 'json_schema') {
            $toolName = (string) ($opts['response_schema']['name'] ?? 'structured_reply');
            $body['tools'] = [[
                'name' => $toolName,
                'description' => 'Return the reply using exactly this structure.',
                'input_schema' => $opts['response_schema']['schema'] ?? [],
            ]];
            $body['tool_choice'] = ['type' => 'tool', 'name' => $toolName];
        }

        $resp = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->retry(2, 500, $this->retryableFailure())->timeout(60)->post(self::BASE.'/messages', $body);

        if (! $resp->successful()) {
            throw new \RuntimeException('Anthropic chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $content = $json['content'][0]['text'] ?? '';
        if ($toolName !== null) {
            // A tool call carries its answer as structured input. Re-encoding it
            // means every provider hands the caller the same thing: a JSON string.
            foreach ($json['content'] ?? [] as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                    $content = (string) json_encode($block['input'] ?? []);
                    break;
                }
            }
        }

        return new LlmResponse(
            content: $content,
            promptTokens: $json['usage']['input_tokens'] ?? 0,
            completionTokens: $json['usage']['output_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
            structuredMode: $mode,
        );
    }

    public function embed(array $texts): array
    {
        throw new \RuntimeException('Anthropic does not support embeddings natively. Use OpenAI or Gemini.');
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
