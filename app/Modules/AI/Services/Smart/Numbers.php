<?php

namespace App\Modules\AI\Services\Smart;

/**
 * Digit handling that does not assume Latin numerals.
 *
 * Customers write figures in the digits of their own script, and a price is
 * still a price when it arrives as ১০০০ or ٥٠٠. Comparing a reply against the
 * evidence has to normalise both sides first, or a perfectly grounded answer
 * looks invented.
 */
class Numbers
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
        '๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9',
        '၀' => '0', '၁' => '1', '၂' => '2', '၃' => '3', '၄' => '4', '၅' => '5', '၆' => '6', '၇' => '7', '၈' => '8', '၉' => '9',
        '૦' => '0', '૧' => '1', '૨' => '2', '૩' => '3', '૪' => '4', '૫' => '5', '૬' => '6', '૭' => '7', '૮' => '8', '૯' => '9',
        '੦' => '0', '੧' => '1', '੨' => '2', '੩' => '3', '੪' => '4', '੫' => '5', '੬' => '6', '੭' => '7', '੮' => '8', '੯' => '9',
    ];

    /** Rewrites every supported digit system into Latin digits. */
    public static function toLatin(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /**
     * A comparable form of one figure: thousands separators removed and
     * trailing decimal zeros dropped, so 1,000 and 1000 match, and so do
     * 1.20 and 1.2.
     */
    public static function canonical(string $raw): ?string
    {
        $value = preg_replace('/[\s,]/u', '', self::toLatin($raw));
        if ($value === null || ! preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return $value === '' ? null : $value;
    }
}
