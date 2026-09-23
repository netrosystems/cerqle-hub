<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Shared\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WidgetAiAvailability
{
    /** @return list<array{enabled: bool, all_day: bool, windows: list<array{start: string, end: string}>}> */
    public function defaults(): array
    {
        return array_map(fn ($day) => ['enabled' => $day < 6, 'all_day' => false, 'windows' => [['start' => '09:00', 'end' => '17:00']]], range(1, 7));
    }

    public function available(ChatWidget $widget, ?CarbonImmutable $at = null, ?string $surface = null): bool
    {
        return $this->reason($widget, $at, $surface) === null;
    }

    /**
     * Why this widget's bot is not answering, or null when it is.
     *
     * available() used to collapse five distinct causes into one boolean, so a
     * silent bot looked identical whether the widget was off, the chatbot was
     * deleted, or a malformed timezone was throwing. Support cannot chase a
     * phantom AI bug without this, so the reason is the source of truth and
     * available() is derived from it.
     */
    public function reason(ChatWidget $widget, ?CarbonImmutable $at = null, ?string $surface = null): ?string
    {
        if (! $this->surfaceEnabled($widget, $surface)) {
            return 'widget_disabled';
        }
        if (! $widget->ai_enabled) {
            return 'ai_switch_off';
        }
        if (! $widget->hasEnabledAiChatbot()) {
            // No chatbot selected, or the selected one was deleted, disabled or
            // belongs to another workspace.
            return 'chatbot_missing';
        }

        $mode = $widget->ai_mode ?? ($widget->ai_enabled ? 'permanent' : 'off');
        if ($mode === 'permanent') {
            return null;
        }
        if ($mode !== 'scheduled') {
            return 'mode_off';
        }

        try {
            $active = app(AiAutomationSchedule::class)->activeWindows(
                $widget->ai_weekly_hours ?? [],
                $widget->ai_timezone ?: config('app.timezone', 'UTC'),
                $at,
            );
        } catch (\Throwable $error) {
            // Previously swallowed, which left a bot permanently and invisibly
            // silent on a malformed schedule or timezone.
            Log::warning('widget_ai.schedule_error', [
                'widget_id' => $widget->id,
                'workspace_id' => $widget->workspace_id,
                'error' => $error->getMessage(),
            ]);

            return 'schedule_error';
        }

        return $active ? null : 'outside_hours';
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
    public function publicState(ChatWidget $widget, ?string $surface = null): array
    {
        return ['mode' => $widget->ai_mode ?? ($widget->ai_enabled ? 'permanent' : 'off'), 'active' => $this->available($widget, surface: $surface)];
    }

    public function surfaceForConversation(?Conversation $conversation): string
    {
        return $conversation?->started_from === Conversation::STARTED_FROM_CUSTOMER_SDK
            ? ChatWidget::SURFACE_SDK
            : ChatWidget::SURFACE_WEB;
    }

    private function surfaceEnabled(ChatWidget $widget, ?string $surface): bool
    {
        return $surface === ChatWidget::SURFACE_SDK
            ? (bool) $widget->sdk_enabled
            : (bool) $widget->enabled;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }
}
