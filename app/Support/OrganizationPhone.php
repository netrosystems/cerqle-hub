<?php

namespace App\Support;

use DateTimeZone;
use InvalidArgumentException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

final class OrganizationPhone
{
    /**
     * @return array<int, array{region:string, dial_code:string, name:string}>
     */
    public static function countries(string $locale = 'en'): array
    {
        $phone = PhoneNumberUtil::getInstance();
        $countries = [];

        foreach ($phone->getSupportedRegions() as $region) {
            $name = class_exists(\Locale::class)
                ? (\Locale::getDisplayRegion('-'.$region, $locale) ?: $region)
                : $region;

            $countries[] = [
                'region' => $region,
                'dial_code' => '+'.$phone->getCountryCodeForRegion($region),
                'name' => $name,
            ];
        }

        usort($countries, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $countries;
    }

    /**
     * @return array{region:string, national:string}
     */
    public static function split(?string $value, string $fallbackRegion = 'US'): array
    {
        $raw = trim((string) $value);
        $fallbackRegion = strtoupper($fallbackRegion);
        if ($raw === '') {
            return ['region' => $fallbackRegion, 'national' => ''];
        }

        $phone = PhoneNumberUtil::getInstance();
        $attempts = [];
        if (str_starts_with($raw, '+')) {
            $attempts[] = [$raw, null];
        } else {
            $digits = preg_replace('/\D+/', '', $raw) ?? '';
            if ($digits !== '' && ! str_starts_with($digits, '0')) {
                $attempts[] = ['+'.$digits, null];
            }
            $attempts[] = [$raw, $fallbackRegion];
        }

        foreach ($attempts as [$candidate, $region]) {
            try {
                $parsed = $phone->parse($candidate, $region);
                if (! $phone->isPossibleNumber($parsed)) {
                    continue;
                }

                return [
                    'region' => $phone->getRegionCodeForNumber($parsed) ?: $fallbackRegion,
                    'national' => $phone->format($parsed, PhoneNumberFormat::NATIONAL),
                ];
            } catch (NumberParseException) {
                // Try the next backward-compatible interpretation.
            }
        }

        return ['region' => $fallbackRegion, 'national' => $raw];
    }

    public static function normalize(?string $value, string $region): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            $phone = PhoneNumberUtil::getInstance();
            $parsed = $phone->parse($raw, strtoupper($region));
            if (! $phone->isValidNumber($parsed)) {
                throw new InvalidArgumentException('Enter a valid phone number for the selected country.');
            }

            return $phone->format($parsed, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            throw new InvalidArgumentException('Enter a valid phone number for the selected country.');
        }
    }

    public static function regionForTimezone(?string $timezone, string $fallback = 'US'): string
    {
        if (! $timezone) {
            return $fallback;
        }

        foreach (PhoneNumberUtil::getInstance()->getSupportedRegions() as $region) {
            if (in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $region), true)) {
                return $region;
            }
        }

        return $fallback;
    }
}
