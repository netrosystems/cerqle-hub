<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\ValueObjects\ChatbotAnswer;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    private ?ChatbotAnswer $lastAnswer = null;

    public function __construct(private LlmGateway $llmGateway, private EmbeddingStore $embedStore) {}

    /** Backward-compatible plain-text entry point used by existing callers. */
    public function run(AiChatbot $bot, Message $inboundMessage, bool $throwProviderErrors = false): ?string
    {
        $this->lastAnswer = $this->answer($bot, $inboundMessage, $throwProviderErrors);

        return $this->lastAnswer->displayBody;
    }

    public function lastAnswer(): ?ChatbotAnswer
    {
        return $this->lastAnswer;
    }

    public function answer(AiChatbot $bot, Message $inboundMessage, bool $throwProviderErrors = false): ChatbotAnswer
    {
        if (! $bot->enabled) {
            return new ChatbotAnswer(null, responseMode: 'disabled');
        }

        $conversation = $inboundMessage->conversation;
        $history = $conversation->messages()->whereIn('type', ['text', 'template'])
            ->when($inboundMessage->id, fn ($query) => $query->where('id', '<', $inboundMessage->id))
            ->latest('id')->take(20)->get()->sortBy('id')->values();

        return $this->generate(
            $bot,
            $this->messageBody($inboundMessage),
            (int) $conversation->workspace_id,
            $history->map(fn (Message $message) => [
                'role' => $message->direction === 'out' ? 'assistant' : 'user',
                'content' => (string) $message->body,
            ])->filter(fn (array $turn) => trim($turn['content']) !== '')->all(),
            $history->contains(fn (Message $message) => $message->direction === 'out'
                && data_get($message->payload, 'ai_answer.response_mode') === 'clarification'),
            'message:'.$inboundMessage->id,
            $conversation->id,
            $throwProviderErrors,
        );
    }

    /**
     * Existing reply/tokens keys remain stable; answer metadata is additive.
     *
     * @param  array<int, mixed>  $history
     * @return array<string, mixed>
     */
    public function runForApi(AiChatbot $bot, string $message, int $workspaceId, array $history = [], bool $throwProviderErrors = false): array
    {
        if (! $bot->enabled) {
            $answer = new ChatbotAnswer(null, responseMode: 'disabled');

            return array_merge(['reply' => null, 'tokens_used' => 0], $answer->toArray());
        }

        $normalisedHistory = $this->normaliseHistory($history);
        $answer = $this->generate(
            $bot,
            $message,
            $workspaceId,
            $normalisedHistory,
            collect($normalisedHistory)->contains(fn (array $turn) => $turn['role'] === 'assistant'
                && str_contains(mb_strtolower($turn['content']), 'clarify')),
            'api:'.hash('sha256', $bot->id.'|'.$message.'|'.json_encode($normalisedHistory)),
            null,
            $throwProviderErrors,
        );

        return array_merge(['reply' => $answer->displayBody, 'tokens_used' => $answer->tokensUsed], $answer->toArray());
    }

    /** @param array<int, array{role:string,content:string}> $history */
    private function generate(AiChatbot $bot, string $message, int $workspaceId, array $history, bool $alreadyClarified, string $idempotencyKey, ?int $conversationId, bool $throwProviderErrors): ChatbotAnswer
    {
        $message = trim($message);
        if ($message === '') {
            return $this->fallback($bot, $alreadyClarified, 0.0);
        }
        if ($this->isAccountSpecific($message)) {
            return new ChatbotAnswer(
                'I can help with general information, but a team member needs to check account or order details. Would you like me to connect you?',
                answerOrigin: 'handoff', responseMode: 'handoff', quickReplies: ['Talk to a person'], handoffOffer: true,
            );
        }
        if (config('ai.smart_bot.business_aware_routing') && $history === [] && $this->isGreeting($message)) {
            return new ChatbotAnswer('Hello! How can I help you today?', answerOrigin: 'greeting', responseMode: 'greeting');
        }

        $bot->loadMissing('knowledgeBase');
        $results = $this->retrieve($bot, $message, $workspaceId, $throwProviderErrors);
        $confidence = (float) ($results[0]['score'] ?? 0.0);
        $citations = $this->citations($results);
        $scope = $this->effectiveScope($bot);
        $threshold = (float) ($bot->confidence_threshold ?: config('ai.smart_bot.confidence_threshold', 0.72));
        $clarificationThreshold = (float) ($bot->clarification_threshold ?: config('ai.smart_bot.clarification_threshold', 0.47));
        $hasVerifiedEvidence = $results !== [] && $confidence >= $threshold;

        if (config('ai.smart_bot.business_aware_routing')) {
            if ($exactFaq = $this->exactFaqAnswer($message, $results)) {
                return new ChatbotAnswer($exactFaq, answerOrigin: 'knowledge_base', citations: $citations, confidence: $confidence);
            }
            if ($scope === 'verified_only' && ! $hasVerifiedEvidence) {
                return $this->fallback($bot, $alreadyClarified, $confidence);
            }
            if ($scope === 'business_only' && ! $hasVerifiedEvidence
                && ! $this->businessRelevant($bot, $message, $confidence, $clarificationThreshold)) {
                return $this->fallback($bot, $alreadyClarified, $confidence);
            }
        }

        $contextChunks = array_map(fn (array $result) => $result['chunk'], $results);
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->buildSystemPrompt($bot, $scope, $contextChunks, $hasVerifiedEvidence)]],
            $history,
            [['role' => 'user', 'content' => $message]],
        );
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                array_merge($this->chatOptions(), ['feature_key' => 'rag_reply', 'idempotency_key' => $idempotencyKey]),
                $bot->id,
                $conversationId,
            );
            if (blank($response->content)) {
                $this->llmGateway->rejectMalformed($response);
            }

            return new ChatbotAnswer(
                $response->content,
                answerOrigin: $hasVerifiedEvidence ? 'knowledge_base' : 'general',
                citations: $hasVerifiedEvidence ? $citations : [],
                tokensUsed: $response->promptTokens + $response->completionTokens,
                confidence: $confidence,
            );
        } catch (\Throwable $error) {
            if ($throwProviderErrors) {
                throw $error;
            }

            return new ChatbotAnswer(
                $bot->fallback_reply ?: 'I could not complete that answer. Would you like help from a team member?',
                answerOrigin: 'fallback', responseMode: 'handoff', quickReplies: ['Talk to a person'],
                handoffOffer: true, confidence: $confidence,
            );
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function retrieve(AiChatbot $bot, string $message, int $workspaceId, bool $throwProviderErrors): array
    {
        if (! $bot->ai_kb_id) {
            return [];
        }
        $queryEmbedding = [];
        try {
            $queryEmbedding = $this->llmGateway->embed($workspaceId, [$message])[0] ?? [];
        } catch (\Throwable $error) {
            if ($throwProviderErrors && ! config('ai.smart_bot.hybrid_retrieval')) {
                throw $error;
            }
        }

        return $this->embedStore->search((int) $bot->ai_kb_id, $queryEmbedding, (int) ($bot->max_context_chunks ?: 5), $message);
    }

    /** @param array<int, AiKbChunk> $contextChunks */
    private function buildSystemPrompt(AiChatbot $bot, string $scope, array $contextChunks, bool $hasVerifiedEvidence): string
    {
        $parts = [trim((string) ($bot->system_prompt ?: 'You are a helpful customer support assistant.'))];
        $kb = $bot->knowledgeBase;
        if ($kb) {
            $parts[] = "Business profile:\nName: ".($kb->business_name ?: $kb->name)
                ."\nPurpose: ".($kb->business_purpose ?: 'Not provided')
                ."\nAudience: ".($kb->target_audience ?: 'Not provided');
        }
        if ($contextChunks !== []) {
            $context = collect($contextChunks)->values()->map(function (AiKbChunk $chunk, int $index): string {
                $title = $chunk->source_title ?: $chunk->document?->title ?: 'Knowledge source';

                return '[Source '.($index + 1).': '.$title."]\n".$chunk->content;
            })->implode("\n\n---\n\n");
            $parts[] = "Verified knowledge context:\n".$context;
        }
        $tone = trim((string) ($bot->tone ?: 'friendly'));
        $evidenceRule = $hasVerifiedEvidence
            ? 'Use the verified context for business facts and do not add unsupported details.'
            : 'No verified business evidence was found. Do not state prices, policies, availability, product capabilities, or other business-specific facts.';
        $scopeRule = match ($scope) {
            'verified_only' => 'Answer only from verified knowledge context.',
            'business_only' => 'Stay focused on this business and closely related general guidance; do not become a general-purpose assistant.',
            default => 'You may answer safe general questions, but business-specific claims still require verified context.',
        };
        $parts[] = <<<PROMPT
Runtime conversation policy (follow this for every reply):
- Sound like a capable human support agent, with a {$tone} and warm tone. Never mention being an AI, a prompt, or a knowledge base.
- Answer the customer's actual request immediately. Never replace a concrete answer with a generic greeting, introduction, or "How can I help?".
- Detect the customer's language and reply in that language. If they request another language, use the requested language. Understand reasonable spelling and grammar mistakes from context.
- Keep ordinary replies to 1-2 short sentences and usually under 45 words. For instructions, give only the essential steps (normally 3-5) and no long preamble.
- {$scopeRule}
- {$evidenceRule}
- Never claim access to an order, account, subscription, identity, private conversation, or customer record. Offer a human team member for those requests.
- When suggesting a URL, make the useful words a Markdown hyperlink such as [view the setup guide](https://example.com). Do not show a long bare URL, and never invent a URL.
- Ask one short, specific question only when a missing detail is essential. Otherwise make the safest helpful assumption and proceed.
- Use recent conversation details naturally, avoid repeating greetings, and do not repeat information the customer already acknowledged.
PROMPT;
        if (! config('ai.smart_bot.business_aware_routing')) {
            $parts[] = 'When verified context is missing, answer from safe general knowledge, but never invent business-specific facts.';
        }

        return implode("\n\n", array_filter($parts));
    }

    private function fallback(AiChatbot $bot, bool $alreadyClarified, float $confidence): ChatbotAnswer
    {
        if (($bot->fallback_mode ?: 'clarify_then_handoff') === 'clarify_then_handoff' && ! $alreadyClarified) {
            return new ChatbotAnswer(
                'Could you clarify what you need help with so I can find the right business information?',
                answerOrigin: 'fallback', responseMode: 'clarification', quickReplies: ['Tell me more', 'Talk to a person'],
                handoffOffer: true, confidence: $confidence,
            );
        }

        return new ChatbotAnswer(
            $bot->fallback_reply ?: 'I do not have verified information for that. Would you like me to connect you with a team member?',
            answerOrigin: 'fallback', responseMode: 'handoff', quickReplies: ['Talk to a person'],
            handoffOffer: true, confidence: $confidence,
        );
    }

    private function effectiveScope(AiChatbot $bot): string
    {
        if (! config('ai.smart_bot.business_aware_routing')) {
            return 'general';
        }
        $scope = in_array($bot->answer_scope, ['business_only', 'verified_only', 'general'], true) ? $bot->answer_scope : 'business_only';

        return $scope === 'business_only' && ! $bot->knowledgeBase?->profile_complete ? 'verified_only' : $scope;
    }

    private function businessRelevant(AiChatbot $bot, string $message, float $retrievalConfidence, float $threshold): bool
    {
        if ($retrievalConfidence >= $threshold) {
            return true;
        }
        $kb = $bot->knowledgeBase;
        if (! $kb) {
            return false;
        }
        $messageTokens = $this->tokens($message);
        $profileTokens = $this->tokens(implode(' ', array_filter([$kb->business_name, $kb->business_purpose, $kb->target_audience])));

        return $messageTokens !== [] && count(array_intersect($messageTokens, $profileTokens)) / count($messageTokens) >= 0.2;
    }

    private function isGreeting(string $message): bool
    {
        return (bool) preg_match('/^(hi|hello|hey|good\s+(morning|afternoon|evening)|assalamu\s+alaikum|salam)[!.?\s]*$/iu', trim($message));
    }

    private function isAccountSpecific(string $message): bool
    {
        return (bool) preg_match('/\b(my\s+(order|account|subscription|invoice|payment|delivery|tracking|profile)|order\s*#|track\s+my|refund\s+my|cancel\s+my|change\s+my)\b/iu', $message);
    }

    /** @param array<int, array<string,mixed>> $results */
    private function exactFaqAnswer(string $message, array $results): ?string
    {
        $normalised = $this->normaliseQuestion($message);
        foreach ($results as $result) {
            /** @var AiKbChunk $chunk */
            $chunk = $result['chunk'];
            if ($chunk->document?->source_type !== 'faq') {
                continue;
            }
            if (preg_match_all('/Q:\s*(.*?)\s*A:\s*(.*?)(?=\n\s*Q:|$)/si', $chunk->content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if ($this->normaliseQuestion($match[1]) === $normalised) {
                        return trim($match[2]);
                    }
                }
            }
        }

        return null;
    }

    /** @param array<int, array<string,mixed>> $results
     * @return list<array{title:string,type:string,url:?string}>
     */
    private function citations(array $results): array
    {
        return collect($results)->map(function (array $result): array {
            /** @var AiKbChunk $chunk */
            $chunk = $result['chunk'];
            $document = $chunk->document;

            return [
                'title' => (string) ($chunk->source_title ?: $document?->title ?: 'Knowledge source'),
                'type' => (string) ($document?->source_type ?: 'text'),
                'url' => filter_var($chunk->source_url, FILTER_VALIDATE_URL) ? $chunk->source_url : null,
            ];
        })->unique(fn (array $citation) => $citation['type'].'|'.$citation['title'].'|'.$citation['url'])
            ->take(5)->values()->all();
    }

    private function messageBody(Message $message): string
    {
        $body = (string) ($message->body ?? '');
        if ($message->channel === 'email') {
            return 'Subject: '.strip_tags((string) ($message->payload['subject'] ?? ''))."\n\n".strip_tags($body);
        }

        return $body;
    }

    /** @param array<int, mixed> $history
     * @return array<int, array{role:string,content:string}>
     */
    private function normaliseHistory(array $history): array
    {
        return collect($history)->filter(fn ($turn) => is_array($turn)
            && in_array($turn['role'] ?? null, ['user', 'assistant'], true)
            && is_string($turn['content'] ?? null) && trim($turn['content']) !== '')
            ->take(-20)->map(fn ($turn) => [
                'role' => $turn['role'], 'content' => mb_substr(trim($turn['content']), 0, 4000),
            ])->values()->all();
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{2,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique($matches[0]));
    }

    private function normaliseQuestion(string $question): string
    {
        return implode(' ', $this->tokens($question));
    }

    /** @return array{max_tokens:int,temperature:float} */
    private function chatOptions(): array
    {
        return ['max_tokens' => 180, 'temperature' => 0.45];
    }
}
