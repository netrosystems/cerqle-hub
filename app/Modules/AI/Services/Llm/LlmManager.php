<?php

namespace App\Modules\AI\Services\Llm;

use App\Models\Workspace;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;

class LlmManager
{
    /** Providers that support embeddings natively. */
    private const EMBED_CAPABLE = ['openai', 'gemini'];

    /** Cerqle-managed inference always uses the system OpenAI account. */
    public static function forManaged(string $featureKey): LlmProviderInterface
    {
        $creds = CredentialResolver::system()->llm('openai');
        if (! $creds) {
            throw new \RuntimeException('Cerqle managed AI is temporarily unavailable.');
        }
        $complex = in_array($featureKey, config('ai.managed.complex_features', []), true);

        return static::build('openai', $creds->toArray(), [
            'chat' => $complex ? config('ai.managed.complex_model') : config('ai.managed.routine_model'),
            'embed' => config('ai.managed.embedding_model'),
        ]);
    }

    /** Resolve customer-owned credentials only; never fall back to Cerqle keys. */
    public static function forWorkspaceByok(int $workspaceId): LlmProviderInterface
    {
        $config = AiProviderConfig::where('workspace_id', $workspaceId)->where('enabled', true)->first();
        if (! $config || empty($config->credentials['api_key'] ?? '')) {
            throw new \RuntimeException('Reconnect your AI provider before continuing.');
        }

        return static::build($config->provider, $config->credentials ?? [], [
            'chat' => $config->default_model_chat,
            'embed' => $config->default_model_embed,
        ]);
    }

    public static function activeByokProvider(int $workspaceId): ?string
    {
        return AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)->whereNotNull('tested_at')->where('test_status', 'passed')
            ->value('provider');
    }

    /** Resolve a provider for chat completions (all providers supported). */
    public static function forWorkspace(int $workspaceId): LlmProviderInterface
    {
        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'anthropic' THEN 2 WHEN 'gemini' THEN 3 WHEN 'deepseek' THEN 4 ELSE 5 END")
            ->first();

        if ($config && ! empty($config->credentials['api_key'] ?? '')) {
            return static::build($config->provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);
        }

        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (['openai', 'anthropic', 'gemini', 'deepseek'] as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        throw new \RuntimeException('No AI provider configured for workspace '.$workspaceId);
    }

    /**
     * Resolve a provider for embeddings only.
     * Anthropic does not support embeddings — it is skipped automatically.
     * Falls back across configured OpenAI → Gemini workspace credentials, then
     * system defaults. A workspace embedding provider does not need to be the
     * active chat provider, allowing DeepSeek to generate RAG answers while a
     * separate provider creates vectors.
     */
    public static function forWorkspaceEmbed(int $workspaceId): LlmProviderInterface
    {
        // Prefer the active provider when it supports embeddings, then any other
        // configured workspace embedding provider. Credentials remain encrypted
        // at rest and are never returned to the browser.
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)
            ->whereIn('provider', self::EMBED_CAPABLE)
            ->orderByDesc('enabled')
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'gemini' THEN 2 ELSE 3 END")
            ->get();

        foreach ($configs as $config) {
            if (empty($config->credentials['api_key'] ?? '')) {
                continue;
            }

            return static::build($config->provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);
        }

        // System-level fallback (embed-capable only)
        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (self::EMBED_CAPABLE as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        throw new \RuntimeException(
            'No embedding-capable AI provider (OpenAI or Gemini) configured for workspace '.$workspaceId.
            '. Anthropic and DeepSeek do not support embeddings.'
        );
    }

    public static function build(string $provider, array $creds, array $models = []): LlmProviderInterface
    {
        $chatModel = static::currentChatModel($provider, $models['chat'] ?? null);
        $embedModel = static::currentEmbedModel($provider, $models['embed'] ?? null);

        return match ($provider) {
            'openai' => new OpenAiProvider(
                $creds['api_key'] ?? '',
                $chatModel,
                $embedModel,
                $creds['organization_id'] ?? null,
            ),
            'anthropic' => new AnthropicProvider($creds['api_key'] ?? '', $chatModel),
            'gemini' => new GeminiProvider($creds['api_key'] ?? '', $chatModel, $embedModel),
            'deepseek' => new DeepSeekProvider($creds['api_key'] ?? '', $chatModel),
            default => throw new \RuntimeException("Unknown LLM provider: {$provider}"),
        };
    }

    private static function currentChatModel(string $provider, ?string $model): string
    {
        $model = trim((string) $model);

        return match ($provider) {
            'openai' => $model !== '' ? $model : 'gpt-4o-mini',
            'anthropic' => $model === '' || str_starts_with($model, 'claude-3-')
                ? 'claude-haiku-4-5-20251001'
                : $model,
            'gemini' => $model === '' || preg_match('/^gemini-(1|2)\./', $model)
                ? 'gemini-3.5-flash'
                : $model,
            'deepseek' => $model !== '' ? $model : 'deepseek-v4-flash',
            default => $model,
        };
    }

    private static function currentEmbedModel(string $provider, ?string $model): string
    {
        $model = trim((string) $model);
        if ($provider === 'openai') {
            return $model !== '' ? $model : 'text-embedding-3-small';
        }
        if ($provider === 'gemini') {
            return in_array($model, ['', 'text-embedding-004', 'embedding-001', 'gemini-embedding-001'], true)
                ? 'gemini-embedding-2'
                : $model;
        }

        return $model;
    }
}
