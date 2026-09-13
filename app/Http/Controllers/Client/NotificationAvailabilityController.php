<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\NotificationAvailability as Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NotificationAvailabilityController extends Controller
{
    private function workspaceId(Request $request): int
    {
        $id = (int) ($request->hasSession() ? $request->session()->get('current_workspace_id', $request->user()->workspace_id) : $request->user()->workspace_id);
        abort_unless($request->user()->status !== 'inactive' && Workspace::find($id)?->isAccessibleBy($request->user()), 403);

        return $id;
    }

    public function show(Request $request, NotificationAvailability $availability): JsonResponse
    {
        return response()->json($availability->state($request->user(), $this->workspaceId($request)));
    }

    public function update(Request $request, NotificationAvailability $availability): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $data = $request->validate([
            'mode' => ['required', 'in:always,scheduled,paused'],
            'timezone' => ['sometimes', 'required', 'timezone:all', 'max:64'],
            'weekly_hours' => ['sometimes', 'required', 'array', 'size:7'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($request, $workspaceId, $data, $availability) {
            // Serialize initial-row creation as well as updates for this member.
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $setting = Setting::where('user_id', $request->user()->id)->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if ($data['revision'] !== ($setting->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Availability changed. Reload and try again.']);
            }
            $state = $availability->state($request->user(), $workspaceId);
            $timezone = $data['timezone'] ?? $state['timezone'];
            $hours = $availability->validateHours($data['weekly_hours'] ?? $state['weekly_hours'], $timezone, $data['mode'] === 'scheduled');
            Setting::updateOrCreate(['user_id' => $request->user()->id, 'workspace_id' => $workspaceId], [
                'mode' => $data['mode'], 'timezone' => $timezone, 'weekly_hours' => $hours,
                'revision' => ($setting->revision ?? 0) + 1,
            ]);
        });

        return response()->json($availability->state($request->user(), $workspaceId));
    }
}
