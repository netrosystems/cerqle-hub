<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Exceptions\AiRequestInProgressException;
use App\Modules\AI\Models\AiCreditUsage;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmResponse;
use Illuminate\Support\Facades\Log;

class LlmGateway
{
    public function __construct(private readonly AiCreditService $credits) {}

    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
    ): LlmResponse {
        $featureKey = $opts['feature_key'] ?? null;
        if (! $featureKey) {
            throw new \LogicException('Every AI chat action must provide a metered feature_key.');
        }
        $idempotencyKey = $opts['idempotency_key'] ?? null;
        $internalRetry = (bool) ($opts['internal_retry'] ?? false);
        unset($opts['feature_key'], $opts['idempotency_key'], $opts['internal_retry']);
        $model = $opts['model'] ?? null;
        $source = $this->credits->mode($workspaceId);
        $usage = null;
        $providerName = null;

        try {
            if ($internalRetry) {
                $prior = AiCreditUsage::where('idempotency_key', $workspaceId.':'.$idempotencyKey)
                    ->where('status', 'succeeded')->firstOrFail();
                $usage = $prior;
                $source = $prior->provider_source;
                $providerName = $prior->provider;
                $provider = $source === 'managed'
                    ? LlmManager::forManaged($featureKey)
                    : LlmManager::forWorkspaceByok($workspaceId);
            } elseif ($source === 'byok') {
                $providerName = LlmManager::activeByokProvider($workspaceId);
                $provider = LlmManager::forWorkspaceByok($workspaceId);
                $usage = $this->credits->beginByok($workspaceId, $featureKey, $idempotencyKey, (string) $providerName);
                if ($usage->status === 'succeeded' && is_array($usage->result_payload)) {
                    return new LlmResponse(...$usage->result_payload, creditUsageId: $usage->id);
                }
                if ($usage->status === 'reserved' && ! $usage->wasRecentlyCreated) {
                    throw new AiRequestInProgressException;
                }
            } else {
                try {
                    $usage = $this->credits->reserve($workspaceId, $featureKey, $idempotencyKey);
                    if ($usage->status === 'succeeded' && is_array($usage->result_payload)) {
                        return new LlmResponse(...$usage->result_payload, creditUsageId: $usage->id);
                    }
                    if ($usage->status === 'reserved' && ! $usage->wasRecentlyCreated) {
                        throw new AiRequestInProgressException;
                    }
                    $providerName = 'openai';
                    $provider = LlmManager::forManaged($featureKey);
                    $source = 'managed';
                } catch (AiCreditsExhaustedException $e) {
                    if ($source !== 'auto_fallback') {
                        throw $e;
                    }
                    $providerName = LlmManager::activeByokProvider($workspaceId);
                    if (! $providerName) {
                        throw new \RuntimeException('Cerqle credits are exhausted. Reconnect your fallback provider.');
                    }
                    $provider = LlmManager::forWorkspaceByok($workspaceId);
                    $source = 'byok';
                    $usage = $this->credits->beginByok($workspaceId, $featureKey, $idempotencyKey, $providerName);
                    if ($usage->status === 'succeeded' && is_array($usage->result_payload)) {
                        return new LlmResponse(...$usage->result_payload, creditUsageId: $usage->id);
                    }
                    if ($usage->status === 'reserved' && ! $usage->wasRecentlyCreated) {
                        throw new AiRequestInProgressException;
                    }
                }
            }
            $response = $provider->chat($messages, $opts);
            if ($usage) {
                $this->credits->complete($usage, [
                    'content' => $response->content,
                    'promptTokens' => $response->promptTokens,
                    'completionTokens' => $response->completionTokens,
                    'model' => $response->model,
                    'latencyMs' => $response->latencyMs,
                ], $response->promptTokens, $response->completionTokens, $response->model, $internalRetry);
            }
        } catch (\Throwable $e) {
            if ($usage && ! $e instanceof AiRequestInProgressException) {
                $this->credits->refund($usage, $e instanceof AiCreditsExhaustedException ? 'credits_exhausted' : 'provider_failure');
            }
            AiRun::create([
                'workspace_id' => $workspaceId,
                'credit_usage_id' => $usage?->id,
                'feature_key' => $featureKey,
                'provider_source' => $source,
                'provider' => $providerName,
                'chatbot_id' => $chatbotId,
                'conversation_id' => $conversationId,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_cents' => 0,
                'latency_ms' => 0,
                'model' => $model,
                'status' => 'error',
            ]);
            Log::error('llm.chat_failed', [
                'workspace_id' => $workspaceId,
                'chatbot_id' => $chatbotId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        AiRun::create([
            'workspace_id' => $workspaceId,
            'credit_usage_id' => $usage?->id,
            'feature_key' => $featureKey,
            'provider_source' => $source,
            'provider' => $providerName,
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost_cents' => 0,
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
            'status' => 'ok',
        ]);

        Log::channel('json')->info('llm.chat', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
        ]);

        return new LlmResponse(
            $response->content,
            $response->promptTokens,
            $response->completionTokens,
            $response->model,
            $response->latencyMs,
            $usage?->id,
        );
    }

    public function rejectMalformed(LlmResponse $response): void
    {
        if ($response->creditUsageId) {
            $this->credits->refundCompleted($response->creditUsageId, 'malformed_response');
        }
    }

    public function embed(int $workspaceId, array $texts): array
    {
        // Use embed-specific provider (skips Anthropic which has no embedding support)
        try {
            $provider = LlmManager::forWorkspaceEmbed($workspaceId);
            $embeddings = $provider->embed($texts);
        } catch (\Throwable $e) {
            AiRun::create([
                'workspace_id' => $workspaceId,
                'feature_key' => 'embedding',
                'provider_source' => 'infrastructure',
                'chatbot_id' => null,
                'conversation_id' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_cents' => 0,
                'latency_ms' => 0,
                'model' => 'embed',
                'status' => 'error',
            ]);
            Log::error('llm.embed_failed', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
        $tokenEstimate = array_sum(array_map(fn ($t) => (int) ceil(strlen($t) / 4), $texts));
        AiRun::create([
            'workspace_id' => $workspaceId,
            'feature_key' => 'embedding',
            'provider_source' => 'infrastructure',
            'chatbot_id' => null,
            'conversation_id' => null,
            'prompt_tokens' => $tokenEstimate,
            'completion_tokens' => 0,
            'cost_cents' => 0,
            'latency_ms' => 0,
            'model' => 'embed',
            'status' => 'ok',
        ]);

        return $embeddings;
    }
}
