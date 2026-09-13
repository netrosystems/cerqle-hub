<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Models\ChatWidget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WidgetAiAvailability
{
    /** @return list<array{enabled: bool, all_day: bool, windows: list<array{start: string, end: string}>}> */
    public function defaults(): array
    {
        return array_map(fn ($day) => ['enabled' => $day < 6, 'all_day' => false, 'windows' => [['start' => '09:00', 'end' => '17:00']]], range(1, 7));
    }

    public function available(ChatWidget $widget, ?CarbonImmutable $at = null): bool
    {
        if (! $widget->enabled || ! $widget->hasEnabledAiChatbot()) {
            return false;
        }
        $mode = $widget->ai_mode ?? ($widget->ai_enabled ? 'permanent' : 'off');
        if ($mode === 'permanent') {
            return true;
        }
        if ($mode !== 'scheduled') {
            return false;
        }
        try {
            return app(AiAutomationSchedule::class)->activeWindows($widget->ai_weekly_hours ?? [], $widget->ai_timezone ?: config('app.timezone', 'UTC'), $at);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<array-key, mixed>  $hours  Untrusted request data, validated below.
     * @return array<array-key, mixed>
     */
    public function validateHours(array $hours, string $timezone, bool $required): array
    {
        foreach ($hours as &$day) {
            if (is_array($day) && in_array($day['all_day'] ?? false, [true, 1, '1'], true)) {
                $day['windows'] = [];
            }
        }
        unset($day);
        Validator::make(['ai_timezone' => $timezone, 'ai_weekly_hours' => $hours], [
            'ai_timezone' => ['required', 'timezone:all'],
            'ai_weekly_hours' => ['required', 'array', 'size:7'],
            'ai_weekly_hours.*.enabled' => ['required', 'boolean'],
            'ai_weekly_hours.*.all_day' => ['required', 'boolean'],
            'ai_weekly_hours.*.windows' => ['present', 'array', 'max:5'],
            'ai_weekly_hours.*.windows.*.start' => ['required', 'date_format:H:i'],
            'ai_weekly_hours.*.windows.*.end' => ['required', 'date_format:H:i'],
        ])->validate();
        if (array_keys($hours) !== range(0, 6)) {
            throw ValidationException::withMessages(['ai_weekly_hours' => 'Provide Monday through Sunday in order.']);
        }
        $intervals = [];
        foreach ($hours as $day => &$entry) {
            $entry['enabled'] = (bool) $entry['enabled'];
            $entry['all_day'] = (bool) $entry['all_day'];
            if (! $entry['enabled']) {
                continue;
            }
            if ($entry['all_day']) {
                $intervals[] = [$day * 1440, ($day + 1) * 1440];

                continue;
            }
            if (! $entry['windows']) {
                throw ValidationException::withMessages(["ai_weekly_hours.$day.windows" => 'Add hours or select All day.']);
            }
            foreach ($entry['windows'] as $index => $window) {
                $start = $this->minutes($window['start']);
                $end = $this->minutes($window['end']);
                if ($start === $end) {
                    throw ValidationException::withMessages(["ai_weekly_hours.$day.windows.$index.end" => 'Start and end must differ; use All day for 24 hours.']);
                }
                $intervals[] = [$day * 1440 + $start, $day * 1440 + $end + ($end < $start ? 1440 : 0)];
            }
        }
        unset($entry);
        if ($required && ! $intervals) {
            throw ValidationException::withMessages(['ai_weekly_hours' => 'Enable at least one day.']);
        }
        foreach ($intervals as $i => [$start, $end]) {
            foreach (array_slice($intervals, $i + 1) as [$otherStart, $otherEnd]) {
                foreach ([-10080, 0, 10080] as $shift) {
                    if ($start < $otherEnd + $shift && $otherStart + $shift < $end) {
                        throw ValidationException::withMessages(['ai_weekly_hours' => 'Hours overlap, including overnight hours.']);
                    }
                }
            }
        }

        return $hours;
    }

    /** @return array{mode: string, active: bool} */
    public function publicState(ChatWidget $widget): array
    {
        return ['mode' => $widget->ai_mode ?? ($widget->ai_enabled ? 'permanent' : 'off'), 'active' => $this->available($widget)];
    }

    private function minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }
}
