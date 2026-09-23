<?php

namespace App\Modules\Inbox\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class WeeklySchedule
{
    public const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /** @param array<string, mixed>|null $schedule */
    public function contains(?array $schedule, ?CarbonInterface $at = null): bool
    {
        if (empty($schedule['enabled'])) {
            return true;
        }

        $timezone = (string) ($schedule['timezone'] ?? '');
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return false;
        }

        $now = CarbonImmutable::instance($at ?? now())->setTimezone($timezone);
        $dayIndex = (int) $now->format('w');
        $minute = ((int) $now->format('H') * 60) + (int) $now->format('i');
        if ($this->dayContains($schedule, $dayIndex, $minute)) {
            return true;
        }

        return $this->previousDayOvernightContains($schedule, ($dayIndex + 6) % 7, $minute);
    }

    /** @param array<string, mixed> $days */
    public function hasOverlaps(array $days): bool
    {
        $week = 7 * 1440;
        $intervals = [];
        foreach (self::DAYS as $index => $key) {
            $day = $days[$key] ?? null;
            if (! is_array($day) || empty($day['enabled'])) {
                continue;
            }
            if (! empty($day['all_day'])) {
                $intervals[] = [$index * 1440, ($index + 1) * 1440];

                continue;
            }
            foreach ($day['windows'] ?? [] as $window) {
                $start = $this->minutes($window['start'] ?? null);
                $end = $this->minutes($window['end'] ?? null);
                if ($start === null || $end === null || $start === $end) {
                    continue;
                }
                $absoluteStart = ($index * 1440) + $start;
                $absoluteEnd = ($index * 1440) + $end + ($end < $start ? 1440 : 0);
                $intervals[] = [$absoluteStart, $absoluteEnd];
            }
        }

        foreach ($intervals as $leftIndex => [$leftStart, $leftEnd]) {
            foreach ($intervals as $rightIndex => [$rightStart, $rightEnd]) {
                if ($rightIndex <= $leftIndex) {
                    continue;
                }
                foreach ([-$week, 0, $week] as $offset) {
                    if ($leftStart < $rightEnd + $offset && $rightStart + $offset < $leftEnd) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schedule */
    private function dayContains(array $schedule, int $dayIndex, int $minute): bool
    {
        $day = $schedule['schedule'][self::DAYS[$dayIndex]] ?? null;
        if (! is_array($day) || empty($day['enabled'])) {
            return false;
        }
        if (! empty($day['all_day'])) {
            return true;
        }

        foreach ($day['windows'] ?? [] as $window) {
            $start = $this->minutes($window['start'] ?? null);
            $end = $this->minutes($window['end'] ?? null);
            if ($start === null || $end === null || $start === $end) {
                continue;
            }
            if (($end > $start && $minute >= $start && $minute < $end) || ($end < $start && $minute >= $start)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schedule */
    private function previousDayOvernightContains(array $schedule, int $dayIndex, int $minute): bool
    {
        $day = $schedule['schedule'][self::DAYS[$dayIndex]] ?? null;
        if (! is_array($day) || empty($day['enabled']) || ! empty($day['all_day'])) {
            return false;
        }

        foreach ($day['windows'] ?? [] as $window) {
            $start = $this->minutes($window['start'] ?? null);
            $end = $this->minutes($window['end'] ?? null);
            if ($start !== null && $end !== null && $end < $start && $minute < $end) {
                return true;
            }
        }

        return false;
    }

    private function minutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            return null;
        }
        [$hours, $minutes] = array_map('intval', explode(':', $value));

        return ($hours * 60) + $minutes;
    }
}
