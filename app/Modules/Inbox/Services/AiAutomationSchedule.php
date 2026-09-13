<?php

namespace App\Modules\Inbox\Services;

use Carbon\CarbonImmutable;

class AiAutomationSchedule
{
    /** @return list<array{enabled: bool, all_day: bool, start: string, end: string}> */
    public function defaults(): array
    {
        return array_map(fn ($day) => ['enabled' => $day < 6, 'all_day' => false, 'start' => '09:00', 'end' => '17:00'], range(1, 7));
    }

    /** @param array<int, array{enabled: bool, all_day: bool, start: string, end: string}> $hours */
    public function active(array $hours, string $timezone, ?CarbonImmutable $at = null): bool
    {
        $local = ($at ?? CarbonImmutable::now())->setTimezone($timezone);
        $minute = $local->hour * 60 + $local->minute;
        $today = $hours[$local->dayOfWeekIso - 1] ?? [];
        $previous = $hours[($local->dayOfWeekIso + 5) % 7] ?? [];
        if ($today['enabled'] ?? false) {
            if ($today['all_day']) {
                return true;
            }
            $start = $this->minutes($today['start']);
            $end = $this->minutes($today['end']);
            if ($start < $end ? $minute >= $start && $minute < $end : $minute >= $start) {
                return true;
            }
        }

        return ($previous['enabled'] ?? false) && ! $previous['all_day']
            && $this->minutes($previous['end']) < $this->minutes($previous['start'])
            && $minute < $this->minutes($previous['end']);
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = explode(':', $time);

        return (int) $hour * 60 + (int) $minute;
    }

    /**
     * Multiple windows use the same wall-clock and overnight rules as grouped hours.
     *
     * @param  list<array{enabled: bool, all_day: bool, windows: list<array{start: string, end: string}>}>  $hours
     */
    public function activeWindows(array $hours, string $timezone, ?CarbonImmutable $at = null): bool
    {
        foreach ($hours as $day => $entry) {
            if (! $entry['enabled']) {
                continue;
            }
            foreach ($entry['all_day'] ? [['start' => '00:00', 'end' => '00:00']] : $entry['windows'] as $window) {
                $single = array_fill(0, 7, ['enabled' => false, 'all_day' => false, 'start' => '00:00', 'end' => '00:00']);
                $single[$day] = ['enabled' => true, 'all_day' => $entry['all_day'], ...$window];
                if ($this->active($single, $timezone, $at)) {
                    return true;
                }
            }
        }

        return false;
    }
}
