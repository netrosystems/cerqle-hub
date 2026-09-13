<?php

namespace App\Services;

use App\Models\NotificationAvailability as Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Services\AiAutomationSchedule;
use App\Modules\Inbox\Services\WidgetAiAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class NotificationAvailability
{
    public function available(User $user, int $workspaceId, ?CarbonImmutable $at = null): bool
    {
        if ($user->status === 'inactive' || ! Workspace::find($workspaceId)?->isAccessibleBy($user)) {
            return false;
        }
        $setting = Setting::where('user_id', $user->id)->where('workspace_id', $workspaceId)->first();

        return $this->active($setting, $at ?? CarbonImmutable::now());
    }

    private function active(?Setting $setting, CarbonImmutable $at): bool
    {
        if (! $setting || $setting->mode === 'always') {
            return true;
        }
        if ($setting->mode !== 'scheduled') {
            return false;
        }
        try {
            return app(AiAutomationSchedule::class)->active($setting->weekly_hours, $setting->timezone, $at);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{workspace_id: int, mode: string, timezone: string, weekly_hours: array<array-key, mixed>, revision: int, active: bool, next_boundary: ?string} */
    public function state(User $user, int $workspaceId): array
    {
        $setting = Setting::where('user_id', $user->id)->where('workspace_id', $workspaceId)->first();
        $now = CarbonImmutable::now('UTC');
        $active = $this->available($user, $workspaceId, $now);
        $next = null;
        if ($setting?->mode === 'scheduled') {
            // Absolute minute traversal preserves both occurrences of repeated DST hours.
            $cursor = $now->startOfMinute()->addMinute();
            for ($minute = 0; $minute < 8 * 1440; $minute++, $cursor = $cursor->addMinute()) {
                if ($this->active($setting, $cursor) !== $this->active($setting, $now)) {
                    $next = $cursor->toIso8601String();
                    break;
                }
            }
        }

        return [
            'workspace_id' => $workspaceId,
            'mode' => $setting->mode ?? 'always',
            'timezone' => $setting->timezone ?? config('app.timezone', 'UTC'),
            'weekly_hours' => $setting->weekly_hours ?? app(AiAutomationSchedule::class)->defaults(),
            'revision' => $setting->revision ?? 0,
            'active' => $active,
            'next_boundary' => $next,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $hours  Untrusted input validated before normalization.
     * @return list<array{enabled: bool, all_day: bool, start: string, end: string}>
     */
    public function validateHours(array $hours, string $timezone, bool $required): array
    {
        $windows = array_map(fn ($day) => is_array($day) ? [
            'enabled' => $day['enabled'] ?? null,
            'all_day' => $day['all_day'] ?? null,
            'windows' => [['start' => $day['start'] ?? '', 'end' => $day['end'] ?? '']],
        ] : $day, $hours);
        try {
            $valid = app(WidgetAiAvailability::class)->validateHours($windows, $timezone, $required);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $key = str_replace(['ai_weekly_hours', 'ai_timezone', '.windows.0', '.windows'], ['weekly_hours', 'timezone', '', ''], $key);
                $errors[$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }

        return array_map(fn ($day) => [
            'enabled' => $day['enabled'], 'all_day' => $day['all_day'],
            'start' => $day['windows'][0]['start'] ?? '09:00',
            'end' => $day['windows'][0]['end'] ?? '17:00',
        ], $valid);
    }
}
