<?php

namespace App\Modules\AI\Services\Smart;

use App\Modules\Shared\Models\Message;

/**
 * Works out which offered choice a customer just picked.
 *
 * On the web widget a tap sends the label back as text. On WhatsApp, Messenger,
 * Instagram and email there are no buttons at all, so the customer types "2" or
 * repeats the wording. Both arrive as an ordinary message, and both have to mean
 * the same thing.
 *
 * Every matcher here is language-agnostic: an ordinal in any digit system, or
 * the label itself compared after normalisation that keeps combining marks —
 * in many scripts two words differ only by one.
 */
class ChoiceResolver
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
        '๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9',
    ];

    /**
     * The choice this message selects, or null when it is not a choice reply.
     *
     * @return array{id:string,label:string,role:string}|null
     */
    public function resolve(Message $inbound, ?string $body = null): ?array
    {
        $body = trim($body ?? (string) $inbound->body);
        if ($body === '' || ! $inbound->conversation_id) {
            return null;
        }

        $offered = $this->lastOffer($inbound);
        if ($offered === []) {
            return null;
        }

        return $this->byOrdinal($body, $offered) ?? $this->byLabel($body, $offered);
    }

    /**
     * The choices on the bot's own most recent outbound message, provided it is
     * still the last thing said and recent enough to be what the customer means.
     *
     * @return list<array{id:string,label:string,role:string}>
     */
    private function lastOffer(Message $inbound): array
    {
        $outbound = Message::where('conversation_id', $inbound->conversation_id)
            ->where('direction', 'out')
            ->when($inbound->id, fn ($query) => $query->where('id', '<', $inbound->id))
            ->latest('id')
            ->first();

        if (! $outbound) {
            return [];
        }

        $minutes = (int) config('ai.smart_bot.choice_ttl_minutes', 30);
        $sentAt = $outbound->sent_at ?? $outbound->created_at;
        if ($sentAt && $sentAt->lt(now()->subMinutes($minutes))) {
            return [];
        }

        return Choices::normalise(
            $outbound->payload['ai_answer']['quick_replies'] ?? [],
            (bool) ($outbound->payload['ai_answer']['handoff_offer'] ?? false),
        );
    }

    /**
     * "2", "2.", "#2" and the same in any digit system.
     *
     * @param  list<array{id:string,label:string,role:string}>  $offered
     * @return array{id:string,label:string,role:string}|null
     */
    private function byOrdinal(string $body, array $offered): ?array
    {
        $normalised = trim(strtr($body, self::DIGITS));
        if (! preg_match('/^[#(\[]?\s*(\d{1,2})\s*[.)\]]?$/u', $normalised, $matches)) {
            return null;
        }

        $index = (int) $matches[1] - 1;

        return $offered[$index] ?? null;
    }

    /**
     * @param  list<array{id:string,label:string,role:string}>  $offered
     * @return array{id:string,label:string,role:string}|null
     */
    private function byLabel(string $body, array $offered): ?array
    {
        $needle = $this->normaliseText($body);
        if ($needle === '') {
            return null;
        }
        foreach ($offered as $choice) {
            if ($this->normaliseText($choice['label']) === $needle) {
                return $choice;
            }
        }

        return null;
    }

    /** Keeps combining marks: dropping them would merge distinct words in many scripts. */
    private function normaliseText(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
