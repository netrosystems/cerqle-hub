<?php

namespace App\Modules\AI\Services\Smart;

/**
 * The tappable choices a reply offers.
 *
 * A choice used to be a bare string, and the widget decided what one meant by
 * testing whether the English word "person" appeared in it — so a translated
 * label silently became an ordinary chat message instead of reaching a human.
 * Carrying an explicit role fixes that for every language at once.
 *
 * Roles are assigned by the server and never by a model: a generated choice can
 * only ever send text back, so no reply can talk the bot into fetching a person,
 * booking or paying.
 */
class Choices
{
    public const ROLE_SEND = 'send';

    public const ROLE_HANDOFF = 'handoff';

    /** Anything resembling markup or a link never becomes a button label. */
    public const FORBIDDEN = ['<', '>', '[', ']', '{', '}', 'http:', 'https:', 'javascript:', 'data:', 'www.'];

    public const MAX_LABEL_LENGTH = 60;

    /** @return array{id:string,label:string,role:string} */
    public static function make(string $label, string $role = self::ROLE_SEND, int $position = 1): array
    {
        return ['id' => 'qr_'.$position, 'label' => $label, 'role' => $role];
    }

    /**
     * Reads whatever is stored on a message into the structured shape.
     *
     * Rows written before roles existed hold plain strings, so they are upgraded
     * here. The old English "person" test survives for those legacy rows only,
     * in this one place — newly written choices always carry their own role.
     *
     * @return list<array{id:string,label:string,role:string}>
     */
    public static function normalise(mixed $stored, bool $handoffOffer = false): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $choices = [];
        foreach ($stored as $entry) {
            $position = count($choices) + 1;

            if (is_array($entry) && is_string($entry['label'] ?? null)) {
                $label = self::cleanLabel($entry['label']);
                if ($label === null) {
                    continue;
                }
                $choices[] = [
                    'id' => is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : 'qr_'.$position,
                    'label' => $label,
                    'role' => ($entry['role'] ?? self::ROLE_SEND) === self::ROLE_HANDOFF ? self::ROLE_HANDOFF : self::ROLE_SEND,
                ];

                continue;
            }

            if (! is_string($entry)) {
                continue;
            }
            $label = self::cleanLabel($entry);
            if ($label === null) {
                continue;
            }
            $choices[] = self::make(
                $label,
                $handoffOffer && str_contains(mb_strtolower($label), 'person') ? self::ROLE_HANDOFF : self::ROLE_SEND,
                $position,
            );
        }

        return $choices;
    }

    /**
     * Sanitises choices a model produced. Every one is forced to send text only.
     *
     * @param  list<mixed>  $raw
     * @return list<array{id:string,label:string,role:string}>
     */
    public static function fromModel(array $raw): array
    {
        $labels = [];
        foreach ($raw as $choice) {
            $label = self::cleanLabel($choice);
            if ($label === null) {
                continue;
            }
            $key = mb_strtolower($label);
            if (! array_key_exists($key, $labels)) {
                $labels[$key] = $label;
            }
        }

        $labels = array_values($labels);
        // One choice reads as the only way forward rather than a choice at all.
        if (count($labels) === 1) {
            return [];
        }

        $choices = [];
        foreach (array_slice($labels, 0, 3) as $index => $label) {
            $choices[] = self::make($label, self::ROLE_SEND, $index + 1);
        }

        return $choices;
    }

    private static function cleanLabel(mixed $choice): ?string
    {
        if (! is_string($choice)) {
            return null;
        }
        // Rejected rather than stripped: silently rewriting a label would show a
        // customer wording nobody authored.
        if (preg_match('/[\p{Cc}\p{Cf}]/u', $choice)) {
            return null;
        }
        $label = trim($choice);
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            return null;
        }
        $lower = mb_strtolower($label);
        foreach (self::FORBIDDEN as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                return null;
            }
        }

        return $label;
    }
}
