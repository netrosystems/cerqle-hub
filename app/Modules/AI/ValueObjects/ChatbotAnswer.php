<?php

namespace App\Modules\AI\ValueObjects;

final class ChatbotAnswer
{
    /**
     * @param  list<string>  $quickReplies
     * @param  list<array{title:string,type:string,url:?string}>  $citations
     */
    public function __construct(
        public readonly ?string $displayBody,
        public readonly string $answerOrigin = 'general',
        public readonly string $responseMode = 'answer',
        public readonly array $quickReplies = [],
        public readonly array $citations = [],
        public readonly bool $handoffOffer = false,
        public readonly int $tokensUsed = 0,
        public readonly float $confidence = 0.0,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'display_body' => $this->displayBody,
            'quick_replies' => $this->quickReplies,
            'answer_origin' => $this->answerOrigin,
            'response_mode' => $this->responseMode,
            'citations' => $this->citations,
            'handoff_offer' => $this->handoffOffer,
            'tokens_used' => $this->tokensUsed,
            'confidence' => round($this->confidence, 3),
        ];
    }
}
