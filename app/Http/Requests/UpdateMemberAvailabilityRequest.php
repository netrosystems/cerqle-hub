<?php

namespace App\Http\Requests;

use App\Modules\Inbox\Services\WeeklySchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMemberAvailabilityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $schedule = $this->input('schedule');
        if (! is_array($schedule)) {
            return;
        }
        foreach ($schedule as $day => $settings) {
            if (is_array($settings) && ! array_key_exists('all_day', $settings)) {
                $schedule[$day]['all_day'] = false;
            }
        }
        $this->merge(['schedule' => $schedule]);
    }

    public function authorize(): bool
    {
        return (bool) $this->user()?->isClientAdministrator();
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'timezone' => ['required_if:enabled,true', 'string', 'max:64', 'timezone:all'],
            'schedule' => ['required_if:enabled,true', 'array'],
            'schedule.*' => ['array'],
            'schedule.*.enabled' => ['required', 'boolean'],
            'schedule.*.all_day' => ['required', 'boolean'],
            'schedule.*.windows' => ['array', 'max:3'],
            'schedule.*.windows.*.start' => ['required', 'date_format:H:i'],
            'schedule.*.windows.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $schedule = $this->input('schedule') ?? [];
            foreach (array_keys($schedule) as $day) {
                if (! in_array($day, WeeklySchedule::DAYS, true)) {
                    $validator->errors()->add("schedule.{$day}", 'Unknown weekday.');
                }
            }
            foreach ($schedule as $day => $settings) {
                foreach (($settings['windows'] ?? []) as $index => $window) {
                    if (($window['start'] ?? null) === ($window['end'] ?? null)) {
                        $validator->errors()->add("schedule.{$day}.windows.{$index}.end", 'Start and end time must be different.');
                    }
                }
            }
            if (app(WeeklySchedule::class)->hasOverlaps($schedule)) {
                $validator->errors()->add('schedule', 'Availability windows cannot overlap, including across overnight day boundaries.');
            }
        }];
    }
}
