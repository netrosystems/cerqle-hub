<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\AI\Models\AiAnswerDiagnostic;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeGap;
use App\Modules\AI\ValueObjects\ChatbotAnswer;
use Illuminate\Support\Facades\Log;

/**
 * Records why each turn ended the way it did.
 *
 * Without this, "the AI is bad" and "a flag is off" are indistinguishable, and
 * so are "we do not support that language" and "the translation step never ran".
 *
 * Writing is best effort by design: a diagnostics failure must never cost a
 * customer their answer, so every write is contained and logged instead of thrown.
 */
class AnswerDiagnostics
{
    /** Origins that mean the bot handled a conversational move, not a question. */
    private const CONVERSATIONAL_ORIGINS = ['greeting', 'conversation', 'starter_question'];

    /**
     * @param  array<string, mixed>  $context  Optional extras: intent, intent_score,
     *                                         intent_method, language, cache_source, passages_used,
     *                                         passages_dropped, context_chars, translated_query,
     *                                         phrase_fallback, credit_result, reason_code, channel.
     */
    public function record(
        AiChatbot $bot,
        int $workspaceId,
        ?int $conversationId,
        string $question,
        ChatbotAnswer $answer,
        int $latencyMs,
        array $context = [],
    ): void {
        if (! config('ai.smart_bot.diagnostics')) {
            return;
        }

        try {
            $decision = $this->decision($answer);

            AiAnswerDiagnostic::create(array_merge([
                // Always the caller's workspace: chunks carry no workspace of
                // their own, so a chunk must never be the source of tenancy.
                'workspace_id' => $workspaceId,
                'kb_id' => $bot->ai_kb_id,
                'chatbot_id' => $bot->id,
                'generation_id' => $bot->knowledgeBase?->active_generation_id,
                'conversation_id' => $conversationId,
                'decision' => $decision,
                'answer_origin' => $answer->answerOrigin,
                'best_score' => round($answer->confidence, 4),
                'completion_tokens' => $answer->tokensUsed,
                'credit_result' => $answer->tokensUsed > 0 ? 'charged' : 'none',
                'latency_ms' => $latencyMs,
            ], $context));

            if ($decision !== 'answer') {
                $this->recordGap($bot, $workspaceId, $question, $answer, $context);
            }
        } catch (\Throwable $error) {
            Log::warning('smart_bot.diagnostics_failed', [
                'workspace_id' => $workspaceId,
                'error' => $error->getMessage(),
            ]);
        }
    }

    /**
     * A gap is a real question the bot could not answer.
     *
     * Greetings, thanks and starter answers are excluded: counting them would
     * bury the questions a client actually needs to write a page about.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordGap(AiChatbot $bot, int $workspaceId, string $question, ChatbotAnswer $answer, array $context): void
    {
        $question = trim($question);
        if (! $bot->ai_kb_id || $question === '' || in_array($answer->answerOrigin, self::CONVERSATIONAL_ORIGINS, true)) {
            return;
        }

        // Keep combining marks: in many scripts two words differ only by one,
        // and collapsing them would merge genuinely different questions.
        $normalised = mb_strtolower(preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $question) ?? $question);
        $fingerprint = sha1(trim(preg_replace('/\s+/u', ' ', $normalised) ?? $normalised));

        $gap = AiKnowledgeGap::firstOrNew([
            'workspace_id' => $workspaceId,
            'kb_id' => (int) $bot->ai_kb_id,
            'fingerprint' => $fingerprint,
        ]);
        $gap->fill([
            'chatbot_id' => $bot->id,
            'sample_question' => mb_substr($question, 0, 500),
            'language' => $context['language'] ?? null,
            'best_score' => max((float) ($gap->best_score ?? 0), round($answer->confidence, 4)),
            'last_seen_at' => now(),
            'occurrences' => ($gap->exists ? (int) $gap->occurrences : 0) + 1,
        ]);
        $gap->save();
    }

    private function decision(ChatbotAnswer $answer): string
    {
        return match ($answer->responseMode) {
            'clarification' => 'clarify',
            'handoff' => 'handoff',
            default => 'answer',
        };
    }
}
