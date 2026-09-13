<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Inbox\Models\AiAutomationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiAutomationController extends Controller
{
    public function update(Request $request, string $group): RedirectResponse
    {
        abort_unless(in_array($group, ['channels', 'email'], true), 404);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless($workspaceId, 403);
        abort_unless(in_array($request->user()->workspaceRole((int) $workspaceId), ['owner', 'admin'], true), 403, 'Only workspace owners and administrators can change AI automation.');
        $data = $request->validate([
            'mode' => ['required', Rule::in(['off', 'on', 'scheduled'])],
            'chatbot_id' => ['nullable', 'integer', Rule::requiredIf(fn () => $request->input('mode') !== 'off')],
            'timezone' => ['required', 'timezone:all'],
            'weekly_hours' => ['required', 'array', 'size:7'],
            'weekly_hours.*' => ['required', 'array:enabled,all_day,start,end'],
            'weekly_hours.*.enabled' => ['required', 'boolean'],
            'weekly_hours.*.all_day' => ['required', 'boolean'],
            'weekly_hours.*.start' => ['required', 'date_format:H:i'],
            'weekly_hours.*.end' => ['required', 'date_format:H:i'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        if (! array_is_list($data['weekly_hours'])) {
            throw ValidationException::withMessages(['weekly_hours' => 'Provide Monday through Sunday in order.']);
        }
        if ($data['mode'] !== 'off' && ! AiChatbot::where('workspace_id', $workspaceId)->where('enabled', true)->whereKey($data['chatbot_id'])->exists()) {
            throw ValidationException::withMessages(['chatbot_id' => 'Select an enabled chatbot from this workspace.']);
        }
        foreach ($data['weekly_hours'] as $index => $day) {
            if ($day['enabled'] && ! $day['all_day'] && $day['start'] === $day['end']) {
                throw ValidationException::withMessages(["weekly_hours.$index.end" => 'Choose different start/end times, or All day.']);
            }
        }
        if ($data['mode'] === 'scheduled' && ! in_array(true, array_column($data['weekly_hours'], 'enabled'))) {
            throw ValidationException::withMessages(['weekly_hours' => 'Enable at least one day.']);
        }
        DB::transaction(function () use ($workspaceId, $group, $data) {
            // Lock workspace to serialize first saves as well as updates.
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            $setting = AiAutomationSetting::where('workspace_id', $workspaceId)->where('group', $group)->lockForUpdate()->first();
            if (($setting ? $setting->revision : 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'Settings changed in another session. Reload before saving.']);
            }
            $values = array_diff_key($data, ['revision' => true]);
            if ($data['mode'] === 'off' && $setting) {
                $values['chatbot_id'] = $setting->chatbot_id;
                $values['weekly_hours'] = $setting->weekly_hours;
                $values['timezone'] = $setting->timezone;
            } elseif ($data['mode'] === 'off') {
                $values['chatbot_id'] = null;
            }
            $values['revision'] = ($setting ? $setting->revision : 0) + 1;
            $values['activated_at'] = $data['mode'] === 'off' ? $setting?->activated_at : now();
            AiAutomationSetting::updateOrCreate(['workspace_id' => $workspaceId, 'group' => $group], $values);
        });

        return back()->with('success', 'AI automation updated.');
    }
}
