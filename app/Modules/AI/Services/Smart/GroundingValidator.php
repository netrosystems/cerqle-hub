<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\AI\Models\AiKbChunk;

/**
 * Checks a generated reply against the evidence before a customer sees it.
 *
 * The model reports whether it stayed grounded, but that is a claim, not proof.
 * A wrong price is the most damaging thing a support bot can say and also the
 * easiest hallucination to catch mechanically: the figure either appears in the
 * evidence or it does not.
 */
class GroundingValidator
{
    private const CURRENCY_SYMBOLS = ['৳', '$', '€', '£', '₹', '¥', '₩', '₦', '₨', '﷼', '₺', '₫', '฿'];

    private const CURRENCY_WORDS = ['usd', 'bdt', 'eur', 'gbp', 'inr', 'jpy', 'aud', 'cad', 'aed', 'sar', 'tk', 'taka', 'dollar', 'dollars', 'euro', 'euros', 'pound', 'pounds', 'rupee', 'rupees'];

    private const TIME_WORDS = ['second', 'seconds', 'sec', 'secs', 'minute', 'minutes', 'min', 'mins', 'hour', 'hours', 'hr', 'hrs', 'day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years'];

    private const DATA_WORDS = ['kb', 'mb', 'gb', 'tb', 'kib', 'mib', 'gib', 'tib'];

    private const PERCENT_WORDS = ['percent', 'percentage', 'pct'];

    /** Words that name something specific enough to need evidence behind it. */
    private const SPECIFIC_TERMS = [
        'plan', 'plans', 'package', 'packages', 'bundle', 'bundles', 'tariff', 'subscription',
        'price', 'prices', 'pricing', 'cost', 'costs', 'fee', 'fees', 'discount', 'refund',
        'warranty', 'quota', 'allowance',
    ];

    /**
     * @param  array{reply:string,quick_replies:list<string>,response_type:string,grounded:bool}  $parsed
     * @param  array<int, array<string,mixed>>  $evidence
     * @return array{result:string,reason:?string}
     */
    public function check(array $parsed, array $evidence, string $customerMessage, ?string $businessProfile = null): array
    {
        $reply = trim($parsed['reply']);
        if ($reply === '') {
            return $this->reject('The reply was empty.');
        }

        // A reply that only asks one short question states no facts. Rejecting
        // those would turn every legitimate follow-up into a handoff, which is
        // the behaviour this whole feature exists to remove.
        $claim = $reply."\n".implode("\n", $parsed['quick_replies']);
        if ($this->isSingleQuestion($reply) && $this->figures($claim) === []) {
            return $this->pass();
        }

        if ($parsed['response_type'] === 'clarification' && ! $parsed['grounded']) {
            return $this->reject('A clarification that states facts has to be grounded in the evidence.');
        }

        $supporting = $this->supportingText($evidence, $customerMessage, $businessProfile);
        $unsupported = $this->unsupportedFigures($claim, $supporting);
        if ($unsupported !== []) {
            return $this->reject('These figures do not appear in the evidence: '.implode(', ', $unsupported).'.');
        }

        if ($evidence === [] && $this->namesSomethingSpecific($claim)) {
            return $this->reject('With no verified evidence the reply must not name plans, packages, prices or products.');
        }

        return $this->pass();
    }

    /**
     * Figures in the claim that the evidence does not support.
     *
     * @return list<string>
     */
    public function unsupportedFigures(string $claim, string $evidence): array
    {
        $supported = $this->figures($evidence);
        $unsupported = [];

        foreach ($this->figures($claim) as $figure) {
            if (! $this->isSupported($figure, $supported)) {
                $unsupported[] = $figure['label'];
            }
        }

        return array_values(array_unique($unsupported));
    }

    /**
     * Every figure worth checking, with the unit it was written in.
     *
     * A bare single digit is ignored: it is almost always a step number or a
     * count written in prose, and checking those rejects good answers.
     *
     * @return list<array{value:string,unit:?string,label:string}>
     */
    private function figures(string $text): array
    {
        $text = mb_strtolower(Numbers::toLatin($text));
        $symbols = preg_quote(implode('', self::CURRENCY_SYMBOLS), '/');
        if (! preg_match_all('/(['.$symbols.'])?\s*(\d[\d,.]*\d|\d)\s*(%|[a-z]+)?/u', $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $figures = [];
        foreach ($matches as $match) {
            $value = Numbers::canonical($match[2] ?? '');
            if ($value === null) {
                continue;
            }
            $unit = $this->unit($match[1] ?? '', trim($match[3] ?? ''));
            if ($unit === null && strlen(str_replace('.', '', $value)) < 2) {
                continue;
            }

            $suffix = $unit !== null && $unit !== 'currency' ? trim($match[3] ?? '') : '';
            $figures[] = [
                'value' => $value,
                'unit' => $unit,
                'label' => trim(($match[1] ?? '').$value.($suffix === '' ? '' : ' '.$suffix)),
            ];
        }

        return $figures;
    }

    /**
     * @param  array{value:string,unit:?string}  $figure
     * @param  list<array{value:string,unit:?string,label:string}>  $supported
     */
    private function isSupported(array $figure, array $supported): bool
    {
        foreach ($supported as $candidate) {
            if ($candidate['value'] !== $figure['value']) {
                continue;
            }
            if ($figure['unit'] === null || $candidate['unit'] === $figure['unit']) {
                return true;
            }
            // "৳128" is supported by "128tk" and by a bare 128 in a price list,
            // but a size or a percentage has to carry its unit on both sides or
            // "5GB" would be matched by "5 days".
            if (in_array($figure['unit'], ['currency', 'time'], true) && $candidate['unit'] === null) {
                return true;
            }
        }

        return false;
    }

    private function unit(string $symbol, string $suffix): ?string
    {
        if ($symbol !== '') {
            return 'currency';
        }
        if ($suffix === '') {
            return null;
        }
        if ($suffix === '%' || in_array($suffix, self::PERCENT_WORDS, true)) {
            return 'percent';
        }
        if (in_array($suffix, self::DATA_WORDS, true)) {
            // gb and gib mean the same thing to a customer.
            return 'data:'.$suffix[0].'b';
        }
        if (in_array($suffix, self::CURRENCY_WORDS, true)) {
            return 'currency';
        }
        if (in_array($suffix, self::TIME_WORDS, true)) {
            return 'time';
        }

        return null;
    }

    /**
     * The only text a fact may come from: the evidence, the business profile, or
     * what the customer said themselves. Conversation history is deliberately
     * excluded — otherwise the bot can confirm its own earlier invention.
     *
     * @param  array<int, array<string,mixed>>  $evidence
     */
    private function supportingText(array $evidence, string $customerMessage, ?string $businessProfile): string
    {
        $parts = [$customerMessage, (string) $businessProfile];
        foreach ($evidence as $result) {
            if (($result['chunk'] ?? null) instanceof AiKbChunk) {
                $parts[] = (string) $result['chunk']->content;
            }
        }

        return implode("\n", array_filter($parts));
    }

    private function isSingleQuestion(string $reply): bool
    {
        $reply = trim($reply);
        if (! preg_match('/[?؟？]$/u', $reply)) {
            return false;
        }

        // One sentence only: a paragraph that happens to end in a question mark
        // still carries statements that need checking.
        return count(preg_split('/(?<=[.!?؟。！？])\s+/u', $reply) ?: []) === 1;
    }

    private function namesSomethingSpecific(string $claim): bool
    {
        $lower = mb_strtolower($claim);
        foreach (self::SPECIFIC_TERMS as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $lower)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{result:string,reason:null} */
    private function pass(): array
    {
        return ['result' => 'passed', 'reason' => null];
    }

    /** @return array{result:string,reason:string} */
    private function reject(string $reason): array
    {
        return ['result' => 'rejected', 'reason' => $reason];
    }
}
