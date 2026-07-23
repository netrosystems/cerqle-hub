<?php

namespace App\Modules\AI\Services;

use Throwable;

class ProviderErrorPresenter
{
    /**
     * Convert provider/network exceptions into safe, actionable client messages.
     * Raw upstream response bodies are deliberately never returned to the browser.
     *
     * @return array{code: string, message: string}
     */
    public static function present(Throwable $exception): array
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'no ai provider configured') => [
                'code' => 'provider_not_configured',
                'message' => 'No enabled AI provider is configured. Save an API key and enable a provider first.',
            ],
            str_contains($message, 'no embedding-capable ai provider'),
            str_contains($message, 'does not support embeddings') => [
                'code' => 'embeddings_not_supported',
                'message' => 'Knowledge bases require an enabled OpenAI or Gemini provider because Anthropic does not create embeddings.',
            ],
            self::containsAny($message, ['429', 'quota', 'rate limit', 'rate_limit', 'insufficient_quota', 'billing']) => [
                'code' => 'provider_quota_exceeded',
                'message' => 'The AI provider quota or rate limit was reached. Check billing and usage limits, then try again.',
            ],
            self::containsAny($message, ['model_not_found', 'model not found', 'does not exist', 'unsupported model', 'invalid model', 'does not have access to model']) => [
                'code' => 'provider_model_unavailable',
                'message' => 'The selected AI model is unavailable for this API project. Choose a model allowed in the provider project settings, then test again.',
            ],
            self::containsAny($message, ['401', '403', 'unauthorized', 'forbidden', 'invalid api key', 'invalid_api_key', 'authentication']) => [
                'code' => 'provider_authentication_failed',
                'message' => 'The AI provider rejected the credentials. Check the API key and provider access, then test again.',
            ],
            self::containsAny($message, ['timed out', 'timeout', 'connection refused', 'could not resolve', 'couldn\'t resolve', 'network is unreachable', 'could not connect']) => [
                'code' => 'provider_unreachable',
                'message' => 'The AI provider could not be reached. Check server networking and try again shortly.',
            ],
            default => [
                'code' => 'provider_request_failed',
                'message' => 'The AI provider request failed. Check the saved provider settings and server logs, then try again.',
            ],
        };
    }

    private static function containsAny(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
