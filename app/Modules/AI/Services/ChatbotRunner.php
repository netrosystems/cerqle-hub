<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\Smart\AnswerDiagnostics;
use App\Modules\AI\Services\Smart\CannedPhrases;
use App\Modules\AI\Services\Smart\Choices;
use App\Modules\AI\Services\Smart\ConversationLanguage;
use App\Modules\AI\Services\Smart\EvidenceSelector;
use App\Modules\AI\Services\Smart\GroundingValidator;
use App\Modules\AI\Services\Smart\IntentClassifier;
use App\Modules\AI\Services\Smart\QueryEmbedder;
use App\Modules\AI\Services\Smart\ReplyContract;
use App\Modules\AI\ValueObjects\ChatbotAnswer;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    private ?ChatbotAnswer $lastAnswer = null;

    public function __construct(
        private LlmGateway $llmGateway,
        private EmbeddingStore $embedStore,
        private QueryEmbedder $queryEmbedder,
        private EvidenceSelector $evidenceSelector,
        private AnswerDiagnostics $diagnostics,
        private IntentClassifier $intents,
        private CannedPhrases $phrases,
        private ConversationLanguage $language,
        private ReplyContract $contract,
        private GroundingValidator $grounding,
    ) {}

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

    /**
     * Resolve, then record exactly once.
     *
     * The ladder below has many exits; funnelling them through here is what lets
     * every turn be accounted for, including the ones that never reach a model.
     *
     * @param  array<int, array{role:string,content:string}>  $history
     */
    private function generate(AiChatbot $bot, string $message, int $workspaceId, array $history, bool $alreadyClarified, string $idempotencyKey, ?int $conversationId, bool $throwProviderErrors): ChatbotAnswer
    {
        $started = microtime(true);
        $answer = $this->resolveAnswer($bot, $message, $workspaceId, $history, $alreadyClarified, $idempotencyKey, $conversationId, $throwProviderErrors);

        $this->diagnostics->record(
            $bot,
            $workspaceId,
            $conversationId,
            $message,
            $answer,
            (int) ((microtime(true) - $started) * 1000),
        );

        return $answer;
    }

    /** @param array<int, array{role:string,content:string}> $history */
    private function resolveAnswer(AiChatbot $bot, string $message, int $workspaceId, array $history, bool $alreadyClarified, string $idempotencyKey, ?int $conversationId, bool $throwProviderErrors): ChatbotAnswer
    {
        $message = trim($message);
        if ($message === '') {
            return $this->fallback($bot, $alreadyClarified, 0.0, $workspaceId, $conversationId);
        }
        if ($this->isAccountSpecific($message)) {
            return new ChatbotAnswer(
                'I can help with general information, but a team member needs to check account or order details. Would you like me to connect you?',
                answerOrigin: 'handoff', responseMode: 'handoff',
                quickReplies: $this->fallbackChoices(['qr_talk_to_person'], $workspaceId, $conversationId, $message),
                handoffOffer: true,
            );
        }
        if ($turn = $this->conversationalTurn($bot, $message, $workspaceId, $history, $conversationId)) {
            return $turn;
        }
        if (config('ai.smart_bot.business_aware_routing') && $history === [] && ! config('ai.smart_bot.conversational_turns')
            && $this->isGreeting($message)) {
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
                return $this->fallback($bot, $alreadyClarified, $confidence, $workspaceId, $conversationId, $message);
            }
            if ($scope === 'business_only' && ! $hasVerifiedEvidence
                && ! $this->businessRelevant($bot, $message, $confidence, $clarificationThreshold)) {
                return $this->fallback($bot, $alreadyClarified, $confidence, $workspaceId, $conversationId, $message);
            }
        }

        $contextChunks = array_map(fn (array $result) => $result['chunk'], $results);
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->buildSystemPrompt($bot, $scope, $contextChunks, $hasVerifiedEvidence)]],
            $history,
            [['role' => 'user', 'content' => $message]],
        );
        $structured = (bool) config('ai.smart_bot.structured_output');
        $options = $structured
            ? $this->contract->requestOptions((bool) ($bot->kb_exact_wording ?? false))
            : $this->chatOptions();
        if ($structured) {
            $messages[0]['content'] .= "\n\n".$this->contract->promptInstruction();
        }

        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                array_merge($options, ['feature_key' => 'rag_reply', 'idempotency_key' => $idempotencyKey]),
                $bot->id,
                $conversationId,
            );
            if (blank($response->content)) {
                // The credit is returned and so is a usable reply: sending the
                // empty body would surface as "the bot said nothing".
                $this->llmGateway->rejectMalformed($response, 'empty_reply');

                return $this->fallback($bot, $alreadyClarified, $confidence, $workspaceId, $conversationId, $message);
            }

            if (! $structured) {
                return new ChatbotAnswer(
                    $response->content,
                    answerOrigin: $hasVerifiedEvidence ? 'knowledge_base' : 'general',
                    citations: $hasVerifiedEvidence ? $citations : [],
                    tokensUsed: $response->promptTokens + $response->completionTokens,
                    confidence: $confidence,
                );
            }

            return $this->structuredAnswer(
                $bot, $response, $messages, $options, $message, $workspaceId, $conversationId,
                $idempotencyKey, $alreadyClarified, $confidence, $citations, $hasVerifiedEvidence, $results,
            );
        } catch (\Throwable $error) {
            if ($throwProviderErrors) {
                throw $error;
            }

            return new ChatbotAnswer(
                $bot->fallback_reply ?: 'I could not complete that answer. Would you like help from a team member?',
                answerOrigin: 'fallback', responseMode: 'handoff',
                quickReplies: $this->fallbackChoices(['qr_talk_to_person'], $workspaceId, $conversationId, $message),
                handoffOffer: true, confidence: $confidence,
            );
        }
    }

    /**
     * Turns a contract reply into an answer, or refuses to send it.
     *
     * The model reports whether it stayed grounded; that claim is checked
     * against the evidence rather than trusted. A rejected reply is retried
     * once inside the SAME reservation, so a customer is charged for one
     * logical answer however many attempts it took — and if the retry fails
     * too, the credit is returned and they get the fallback instead.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @param  list<array{title:string,type:string,url:?string}>  $citations
     * @param  array<int, array<string,mixed>>  $results
     */
    private function structuredAnswer(
        AiChatbot $bot,
        LlmResponse $response,
        array $messages,
        array $options,
        string $message,
        int $workspaceId,
        ?int $conversationId,
        string $idempotencyKey,
        bool $alreadyClarified,
        float $confidence,
        array $citations,
        bool $hasVerifiedEvidence,
        array $results,
    ): ChatbotAnswer {
        $parsed = $this->contract->parse($response->content);
        $verdict = $parsed === null
            ? ['result' => 'rejected', 'reason' => 'The reply was not in the required format.']
            : $this->validateGrounding($parsed, $results, $message, $bot);

        if ($verdict['result'] === 'rejected') {
            $retry = $this->retryOnce($messages, $options, $workspaceId, $conversationId, $bot, $idempotencyKey, (string) $verdict['reason']);
            $retryParsed = $retry ? $this->contract->parse($retry->content) : null;
            $retryVerdict = $retryParsed === null
                ? ['result' => 'rejected', 'reason' => null]
                : $this->validateGrounding($retryParsed, $results, $message, $bot);

            if ($retry !== null && $retryVerdict['result'] === 'passed') {
                $response = $retry;
                $parsed = $retryParsed;
            } else {
                // Two attempts, neither usable: the client pays for answers,
                // not for attempts.
                $this->llmGateway->rejectMalformed($response, 'ungrounded_answer');

                return $this->fallback($bot, $alreadyClarified, $confidence, $workspaceId, $conversationId, $message);
            }
        }

        $this->language->remember($conversationId, $parsed['language'] ?? null);
        $body = $this->choiceText($parsed['reply']);

        return new ChatbotAnswer(
            $body,
            answerOrigin: $hasVerifiedEvidence ? 'knowledge_base' : 'general',
            responseMode: $parsed['response_type'] === 'clarification' ? 'clarification' : 'answer',
            /** @phpstan-ignore-next-line argument.type — structured choices are an accepted shape */
            quickReplies: Choices::fromModel($parsed['quick_replies']),
            citations: $hasVerifiedEvidence ? $citations : [],
            tokensUsed: $response->promptTokens + $response->completionTokens,
            confidence: $confidence,
        );
    }

    /**
     * @param  array{reply:string,quick_replies:list<string>,response_type:string,grounded:bool}  $parsed
     * @param  array<int, array<string,mixed>>  $results
     * @return array{result:string,reason:?string}
     */
    private function validateGrounding(array $parsed, array $results, string $message, AiChatbot $bot): array
    {
        if (! config('ai.smart_bot.grounding_validation')) {
            return ['result' => 'passed', 'reason' => null];
        }

        $kb = $bot->knowledgeBase;
        $profile = $kb
            ? implode(' ', array_filter([$kb->business_name, $kb->business_purpose, $kb->target_audience]))
            : null;

        return $this->grounding->check($parsed, $results, $message, $profile);
    }

    /**
     * One more attempt under the same reservation. internal_retry makes the
     * gateway reuse the existing usage row, so tokens accumulate and credits
     * do not.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     */
    private function retryOnce(array $messages, array $options, int $workspaceId, ?int $conversationId, AiChatbot $bot, string $idempotencyKey, string $reason): ?LlmResponse
    {
        $messages[0]['content'] .= "\n\nYour previous reply was rejected: ".$reason
            .' Answer again using only facts that appear in the evidence above.';

        try {
            return $this->llmGateway->chat(
                $workspaceId,
                $messages,
                array_merge($options, [
                    'feature_key' => 'rag_reply',
                    'idempotency_key' => $idempotencyKey,
                    'internal_retry' => true,
                ]),
                $bot->id,
                $conversationId,
            );
        } catch (\Throwable) {
            // A failed retry is not worth losing the fallback over.
            return null;
        }
    }

    /** Caps the reply so a customer is not handed an essay. */
    private function choiceText(string $reply): string
    {
        $limit = (int) config('ai.smart_bot.max_reply_words', 70);
        $words = preg_split('/\s+/u', trim($reply)) ?: [];
        if ($limit <= 0 || count($words) <= $limit) {
            return trim($reply);
        }

        $truncated = implode(' ', array_slice($words, 0, $limit));
        if (preg_match('/^(.*[.!?؟。！？])\s/su', $truncated.' ', $matches)) {
            return trim($matches[1]);
        }

        return rtrim($truncated, " \t\n\r\0\x0B,;:").'…';
    }

    /**
     * Greetings, thanks and closings, answered in the customer's language for
     * nothing.
     *
     * The old greeting shortcut only fired on the very first turn and only
     * matched English, so a customer saying "thanks" mid-conversation in their
     * own language fell through retrieval, found nothing, and was handed to a
     * person for no reason. Intent here is recognised by meaning rather than by
     * spelling, so it works in languages nobody listed.
     *
     * @param  array<int, array{role:string,content:string}>  $history
     */
    private function conversationalTurn(AiChatbot $bot, string $message, int $workspaceId, array $history, ?int $conversationId): ?ChatbotAnswer
    {
        if (! config('ai.smart_bot.conversational_turns')) {
            return null;
        }

        $vector = $this->queryEmbedder->vector($workspaceId, $message);
        $result = $this->intents->classify($workspaceId, $message, $vector, $this->botAskedAQuestion($history));

        $phraseKey = match ($result['intent']) {
            'greeting' => 'greeting',
            'thanks' => 'thanks_ack',
            'closing' => 'closing',
            'decline' => 'decline_ack',
            default => null,
        };
        if ($phraseKey === null) {
            return null;
        }

        $language = $this->language->resolve($conversationId);
        $reply = $this->phrases->get($phraseKey, $language, $workspaceId, $message);

        // A greeting never reaches the model that would otherwise name the
        // language, so without this a conversation that opens with "Merhaba"
        // stays 'und' forever and pays for a round trip on every single turn.
        $this->language->remember($conversationId, $this->phrases->lastLanguage(), 'phrase');

        return new ChatbotAnswer(
            $reply,
            answerOrigin: 'conversation',
            responseMode: 'answer',
        );
    }

    /**
     * Whether the bot's own last turn asked something, so a bare yes or no can
     * be read as an answer to it rather than as an unanswerable question.
     *
     * @param  array<int, array{role:string,content:string}>  $history
     */
    private function botAskedAQuestion(array $history): bool
    {
        $last = end($history);

        return is_array($last)
            && $last['role'] === 'assistant'
            && preg_match('/[?\x{061F}\x{FF1F}]\s*$/u', trim($last['content'])) === 1;
    }

    /** @return array<int, array<string,mixed>> */
    private function retrieve(AiChatbot $bot, string $message, int $workspaceId, bool $throwProviderErrors): array
    {
        if (! $bot->ai_kb_id || ! $this->knowledgeBaseBelongsToWorkspace((int) $bot->ai_kb_id, $workspaceId)) {
            return [];
        }

        $queryEmbedding = $this->queryEmbedder->vector(
            $workspaceId,
            $message,
            $throwProviderErrors && ! config('ai.smart_bot.hybrid_retrieval'),
        );

        $results = $this->embedStore->search(
            (int) $bot->ai_kb_id,
            $queryEmbedding,
            (int) ($bot->max_context_chunks ?: 5),
            $message,
        );

        return $this->evidenceSelector->select($results, (int) ($bot->max_context_chunks ?: 5))['passages'];
    }

    /**
     * ai_kb_chunks carries no workspace_id, so retrieval trusts the bot row for
     * tenancy. Confirm the knowledge base really belongs to the acting workspace
     * before any of its content can reach a prompt.
     */
    private function knowledgeBaseBelongsToWorkspace(int $kbId, int $workspaceId): bool
    {
        return AiKnowledgeBase::whereKey($kbId)->where('workspace_id', $workspaceId)->exists();
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

    private function fallback(AiChatbot $bot, bool $alreadyClarified, float $confidence, ?int $workspaceId = null, ?int $conversationId = null, ?string $message = null): ChatbotAnswer
    {
        // The buttons here were already offered in the customer's language while
        // the sentence above them stayed English, which reads worse than being
        // consistently English. A reply the client wrote themselves is left
        // exactly as they wrote it — their words, their language.
        $phrase = function (string $key, string $english) use ($workspaceId, $conversationId, $message): string {
            if (! config('ai.smart_bot.multilingual_handover') || $workspaceId === null) {
                return $english;
            }

            return $this->phrases->get($key, $this->language->resolve($conversationId), $workspaceId, $message) ?: $english;
        };

        if (($bot->fallback_mode ?: 'clarify_then_handoff') === 'clarify_then_handoff' && ! $alreadyClarified) {
            return new ChatbotAnswer(
                $phrase('clarify_prompt', 'Could you clarify what you need help with so I can find the right business information?'),
                answerOrigin: 'fallback', responseMode: 'clarification',
                quickReplies: $this->fallbackChoices(['qr_tell_me_more', 'qr_talk_to_person'], $workspaceId, $conversationId, $message),
                handoffOffer: true, confidence: $confidence,
            );
        }

        $handoff = $bot->fallback_reply ?: trim(
            $phrase('no_verified_info', 'I do not have verified information for that.')
            .' '.$phrase('handoff_offer', 'Would you like me to connect you with a team member?')
        );

        return new ChatbotAnswer(
            $handoff,
            answerOrigin: 'fallback', responseMode: 'handoff',
            quickReplies: $this->fallbackChoices(['qr_talk_to_person'], $workspaceId, $conversationId, $message),
            handoffOffer: true, confidence: $confidence,
        );
    }

    /**
     * The bot's own offers, in the customer's language and carrying their roles.
     *
     * Only server-authored choices may ask for a person; a model-produced choice
     * can never do more than send text back. While the flag is off these stay
     * the historical English strings, so nothing reading stored messages sees a
     * shape it does not expect.
     *
     * @param  list<string>  $keys
     * @return list<string>|list<array{id:string,label:string,role:string}>
     */
    private function fallbackChoices(array $keys, ?int $workspaceId, ?int $conversationId, ?string $message): array
    {
        $english = ['qr_tell_me_more' => 'Tell me more', 'qr_talk_to_person' => 'Talk to a person'];

        if (! config('ai.smart_bot.multilingual_handover')) {
            return array_map(static fn (string $key): string => $english[$key], $keys);
        }

        $language = $this->language->resolve($conversationId);
        $choices = [];
        foreach ($keys as $index => $key) {
            $choices[] = Choices::make(
                $this->phrases->get($key, $language, $workspaceId, $message) ?: $english[$key],
                $key === 'qr_talk_to_person' ? Choices::ROLE_HANDOFF : Choices::ROLE_SEND,
                $index + 1,
            );
        }

        return $choices;
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
