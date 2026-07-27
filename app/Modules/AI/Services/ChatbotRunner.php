<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    public function __construct(
        private LlmGateway $llmGateway,
        private EmbeddingStore $embedStore,
    ) {}

    public function run(AiChatbot $bot, Message $inboundMessage, bool $throwProviderErrors = false): ?string
    {
        if (! $bot->enabled) {
            return null;
        }

        $conversation = $inboundMessage->conversation;
        $body = $inboundMessage->body ?? '';
        $workspaceId = $conversation->workspace_id;

        // 1. Embed the user query
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$body]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable $e) {
                if ($throwProviderErrors) {
                    throw $e;
                }

                // proceed without retrieval
            }
        }

        // 2. Retrieve top-k relevant chunks
        $contextChunks = [];
        if ($bot->ai_kb_id && ! empty($queryEmbedding)) {
            $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            $contextChunks = array_column($results, 'chunk');
        }

        // Inject the customer's recent orders so the bot can answer "where is my order?".
        // Gated on a connected Ecommerce store; resolved lazily to avoid a hard
        // cross-module dependency (matches the CredentialResolver class_exists pattern).
        $orderSummary = $this->orderSummary($workspaceId, $conversation->contact_id);
        $systemPrompt = $this->buildSystemPrompt($bot, $contextChunks, $orderSummary);

        // Load recent conversation turns as context (last 20 messages)
        $history = [];
        $recentMessages = $conversation->messages()
            ->whereIn('type', ['text', 'template'])
            ->where('id', '!=', $inboundMessage->id)
            ->latest('id')
            ->take(20)
            ->get()
            ->sortBy('id')
            ->values();

        foreach ($recentMessages as $m) {
            if (! $m->body) {
                continue;
            }
            $history[] = [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => $m->body,
            ];
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $body]],
        );

        // 4. Call LLM
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                $this->chatOptions(),
                $bot->id,
                $conversation->id,
            );

            return $response->content;
        } catch (\Throwable $e) {
            if ($throwProviderErrors) {
                throw $e;
            }

            // Fallback
            return $bot->fallback_reply ?? null;
        }
    }

    /**
     * Build a short summary of the contact's recent orders, or null when the
     * Ecommerce module is absent / no store is connected / no orders exist.
     */
    private function orderSummary(int $workspaceId, ?int $contactId): ?string
    {
        $storeModel = 'App\Modules\Ecommerce\Models\EcommerceStore';
        $orderModel = 'App\Modules\Ecommerce\Models\EcommerceOrder';

        if (! $contactId || ! class_exists($storeModel) || ! class_exists($orderModel)) {
            return null;
        }

        $hasStore = $storeModel::where('workspace_id', $workspaceId)
            ->where('status', 'connected')
            ->exists();
        if (! $hasStore) {
            return null;
        }

        $orders = $orderModel::where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->latest('placed_at')
            ->take(3)
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        return $orders->map(function ($o) {
            $parts = ['Order '.($o->number ?: $o->external_order_id)];
            if ($o->fulfillment_status) {
                $parts[] = 'status: '.$o->fulfillment_status;
            }
            $parts[] = 'total: '.$o->currency.' '.$o->total;
            if ($o->tracking_url) {
                $parts[] = 'tracking: '.$o->tracking_url;
            }
            if ($o->placed_at) {
                $parts[] = 'placed: '.$o->placed_at->toDateString();
            }

            return '- '.implode(', ', $parts);
        })->implode("\n");
    }

    /**
     * API-friendly variant: run the chatbot with a plain text message.
     * Does not require an existing Message/Conversation model.
     *
     * @param  array  $history  Array of {role, content} prior turns (optional)
     * @return array{reply: string|null, tokens_used: int}
     */
    public function runForApi(
        AiChatbot $bot,
        string $message,
        int $workspaceId,
        array $history = [],
        bool $throwProviderErrors = false,
    ): array {
        // 1. Embed the user query for RAG
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$message]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
            }
        }

        // 2. Retrieve top-k relevant chunks
        $contextChunks = [];
        if ($bot->ai_kb_id && ! empty($queryEmbedding)) {
            $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            $contextChunks = array_column($results, 'chunk');
        }

        // 3. Build messages array
        $systemPrompt = $this->buildSystemPrompt($bot, $contextChunks);

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $this->normaliseHistory($history),
            [['role' => 'user', 'content' => $message]],
        );

        // 4. Call LLM
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                $this->chatOptions(),
                $bot->id,
            );

            return [
                'reply' => $response->content,
                'tokens_used' => $response->promptTokens + $response->completionTokens,
            ];
        } catch (\Throwable $e) {
            if ($throwProviderErrors) {
                throw $e;
            }

            return ['reply' => $bot->fallback_reply ?? null, 'tokens_used' => 0];
        }
    }

    /**
     * Combine the workspace's brand instructions with a stable conversation
     * policy. Keeping this policy at the end makes concise, multilingual,
     * human replies consistent even when a customer supplies a verbose prompt.
     *
     * @param  array<int, mixed>  $contextChunks
     */
    private function buildSystemPrompt(AiChatbot $bot, array $contextChunks = [], ?string $orderSummary = null): string
    {
        $parts = [
            trim((string) ($bot->system_prompt ?: 'You are a helpful customer support assistant.')),
        ];

        if (! empty($contextChunks)) {
            $context = implode("\n\n---\n\n", array_map(fn ($chunk) => $chunk->content, $contextChunks));
            $parts[] = "Knowledge base context:\n".$context;
        }

        if ($orderSummary !== null) {
            $parts[] = "Use this order information only when the customer asks about an order, shipping, or delivery:\n".$orderSummary;
        }

        $tone = trim((string) ($bot->tone ?: 'friendly'));
        $parts[] = <<<PROMPT
Runtime conversation policy (follow this for every reply):
- Sound like a capable human support agent, with a {$tone} and warm tone. Never mention being an AI, a prompt, or a knowledge base.
- Answer the customer's actual request immediately. Never replace a concrete answer with a generic greeting, introduction, or "How can I help?".
- Detect the customer's language and reply in that language. If they request another language, use the requested language. Understand reasonable spelling and grammar mistakes from context.
- Keep ordinary replies to 1-3 short sentences and usually under 60 words. For instructions, give only the essential steps (normally 3-5) and no long preamble. Use plain text and light numbering only when it improves clarity.
- Use relevant knowledge-base facts when available. If context is missing, make a sensible decision and answer from safe general knowledge. Do not invent prices, account data, policies, availability, or other business-specific facts.
- Ask one short, specific question only when a missing detail is essential. Otherwise make the most helpful reasonable assumption and proceed.
- Use recent conversation details naturally, avoid repeating greetings, and do not repeat information the customer already acknowledged.
- If the request is ambiguous, briefly state the likely interpretation and help with it. If it is account-specific or high risk and cannot be verified, say so briefly and offer the safest next step or a human handoff.
PROMPT;

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Keep only safe conversational roles and bounded text from API callers.
     */
    private function normaliseHistory(array $history): array
    {
        return collect($history)
            ->filter(fn ($turn) => is_array($turn)
                && in_array($turn['role'] ?? null, ['user', 'assistant'], true)
                && is_string($turn['content'] ?? null)
                && trim($turn['content']) !== '')
            ->take(-20)
            ->map(fn ($turn) => [
                'role' => $turn['role'],
                'content' => mb_substr(trim($turn['content']), 0, 4000),
            ])
            ->values()
            ->all();
    }

    private function chatOptions(): array
    {
        return [
            'max_tokens' => 240,
            'temperature' => 0.45,
        ];
    }
}
