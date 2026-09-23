<?php

namespace App\Modules\AI\Services\Smart;

/**
 * The shape a generated reply must arrive in.
 *
 * Asking for free prose is what lets Knowledge Base wording get pasted back
 * verbatim and lets the model invent its own buttons. Asking for a fixed JSON
 * object gives the server something it can check before a customer sees it.
 *
 * The schema is also restated in the prompt, because the weakest provider in
 * the ladder cannot enforce it and has to be persuaded instead.
 */
class ReplyContract
{
    public const NAME = 'smart_bot_reply';

    /** @return array<string, mixed> */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => "The reply, in the customer's own language."],
                'quick_replies' => [
                    'type' => 'array',
                    'description' => 'Usually empty. Only fill this when the evidence itself puts a choice to the '
                        .'customer — for example when it says to ask which of several options applies. Then give '
                        .'those options, two or three, in the reply language. Never invent follow-up suggestions.',
                    'items' => ['type' => 'string'],
                ],
                'response_type' => ['type' => 'string', 'enum' => ['answer', 'clarification', 'fallback']],
                'grounded' => ['type' => 'boolean', 'description' => 'True only if every fact came from the evidence above.'],
                'language' => ['type' => 'string', 'description' => "BCP-47 tag of the customer's language, for example es or pt-BR."],
            ],
            'required' => ['reply', 'quick_replies', 'response_type', 'grounded', 'language'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function requestOptions(bool $exactWording): array
    {
        return [
            'max_tokens' => (int) config('ai.smart_bot.max_tokens', 320),
            'temperature' => (float) ($exactWording
                ? config('ai.smart_bot.temperature_exact_wording', 0.2)
                : config('ai.smart_bot.temperature', 0.4)),
            'response_schema' => ['name' => self::NAME, 'schema' => $this->schema()],
        ];
    }

    /** The contract spelled out for providers that cannot enforce a schema. */
    public function promptInstruction(): string
    {
        return 'Reply with one JSON object and nothing else: '
            .'{"reply": "...", "quick_replies": ["...", "..."], '
            .'"response_type": "answer" | "clarification" | "fallback", '
            .'"grounded": true | false, "language": "<BCP-47 tag>"}. '
            .'Leave quick_replies empty unless the evidence itself asks the customer to choose '
            .'between options; then list those options, two or three, each under 60 characters, in '
            .'the same language as the reply. Never offer choices of your own invention. '
            .'Set grounded to true only when every fact in the reply appears in the evidence above.';
    }

    /**
     * Reads a model reply, tolerating a provider that wrapped the object in
     * prose or a code fence.
     *
     * @return array{reply:string,quick_replies:list<string>,response_type:string,grounded:bool,language:?string}|null
     */
    public function parse(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $balanced = $this->firstBalancedObject($raw);
            $decoded = $balanced === null ? null : json_decode($balanced, true);
        }
        if (! is_array($decoded) || ! is_string($decoded['reply'] ?? null)) {
            return null;
        }

        $choices = [];
        foreach ((array) ($decoded['quick_replies'] ?? []) as $choice) {
            if (is_string($choice)) {
                $choices[] = $choice;
            }
        }
        $type = is_string($decoded['response_type'] ?? null) ? $decoded['response_type'] : 'answer';

        return [
            'reply' => trim($decoded['reply']),
            'quick_replies' => $choices,
            'response_type' => in_array($type, ['answer', 'clarification', 'fallback'], true) ? $type : 'answer',
            'grounded' => (bool) ($decoded['grounded'] ?? false),
            'language' => is_string($decoded['language'] ?? null) ? $decoded['language'] : null,
        ];
    }

    /**
     * Finds the first complete object, counting braces while respecting string
     * literals — a reply that legitimately contains a brace inside quotes must
     * not truncate the object early.
     */
    private function firstBalancedObject(string $text): ?string
    {
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($index = $start; $index < $length; $index++) {
            $character = $text[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }
}
