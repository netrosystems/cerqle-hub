<?php

namespace App\Modules\AI\Services\Smart;

/**
 * The writing system a message is in.
 *
 * This is a Unicode script property, not a language list: it says "these are
 * Arabic letters", never "this customer speaks Arabic". Many languages share a
 * script and one language can use several, so this is only ever used as a cache
 * key before the real language tag is known — never to choose a reply language.
 */
class ScriptHint
{
    /** Ordered so the first script actually present wins. */
    private const SCRIPTS = [
        'arab' => '\p{Arabic}',
        'beng' => '\p{Bengali}',
        'cyrl' => '\p{Cyrillic}',
        'deva' => '\p{Devanagari}',
        'ethi' => '\p{Ethiopic}',
        'grek' => '\p{Greek}',
        'gujr' => '\p{Gujarati}',
        'guru' => '\p{Gurmukhi}',
        'hang' => '\p{Hangul}',
        'hani' => '\p{Han}',
        'hebr' => '\p{Hebrew}',
        'jpan' => '\p{Hiragana}\p{Katakana}',
        'khmr' => '\p{Khmer}',
        'knda' => '\p{Kannada}',
        'laoo' => '\p{Lao}',
        'mlym' => '\p{Malayalam}',
        'mymr' => '\p{Myanmar}',
        'orya' => '\p{Oriya}',
        'sinh' => '\p{Sinhala}',
        'taml' => '\p{Tamil}',
        'telu' => '\p{Telugu}',
        'thaa' => '\p{Thaana}',
        'thai' => '\p{Thai}',
        'tibt' => '\p{Tibetan}',
    ];

    /** A short script code, 'latn' for Latin text, or 'zyyy' when there are no letters. */
    public static function of(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'zyyy';
        }

        foreach (self::SCRIPTS as $code => $pattern) {
            if (preg_match('/['.$pattern.']/u', $text)) {
                return $code;
            }
        }

        return preg_match('/\p{Latin}/u', $text) ? 'latn' : 'zyyy';
    }
}
