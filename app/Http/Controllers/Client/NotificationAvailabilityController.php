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
        $workspaceId = $this->workspaceId($request);
        $canEdit = $request->user()->isClientAdministrator() && $request->user()->client_id
            && Workspace::find($workspaceId)?->client_id === $request->user()->client_id;

        return response()->json([...$availability->state($request->user(), $workspaceId), 'can_edit' => (bool) $canEdit]);
    }

    public function update(Request $request, NotificationAvailability $availability): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $this->authorizeMember($request->user(), $request->user(), Workspace::findOrFail($workspaceId));

        return $this->save($request, $availability, $request->user(), $workspaceId);
    }

    private function authorizeMember(User $actor, User $member, Workspace $workspace): void
    {
        abort_unless($actor->isActive() && $actor->client_id && $actor->isClientAdministrator(), 403);
        abort_unless($workspace->client_id === $actor->client_id && $member->client_id === $actor->client_id, 404);
        abort_unless($workspace->isAccessibleBy($member), 404);
    }

    public function showMember(Request $request, NotificationAvailability $availability, User $member, Workspace $workspace): JsonResponse
    {
        $this->authorizeMember($request->user(), $member, $workspace);

        return response()->json([...$availability->state($member, $workspace->id), 'member_id' => $member->id, 'can_edit' => true]);
    }

    public function updateMember(Request $request, NotificationAvailability $availability, User $member, Workspace $workspace): JsonResponse
    {
        $this->authorizeMember($request->user(), $member, $workspace);

        return $this->save($request, $availability, $member, $workspace->id);
    }

    private function save(Request $request, NotificationAvailability $availability, User $member, int $workspaceId): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:always,scheduled,paused'],
            'timezone' => ['sometimes', 'required', 'timezone:all', 'max:64'],
            'weekly_hours' => ['sometimes', 'required', 'array', 'size:7'],
            'revision' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($request, $member, $workspaceId, $data, $availability) {
            // Serialize initial-row creation as well as updates for this member.
            $member = User::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($request->user()->id);
            $this->authorizeMember($actor, $member, Workspace::findOrFail($workspaceId));
            $setting = Setting::where('user_id', $member->id)->where('workspace_id', $workspaceId)->lockForUpdate()->first();
            if ($data['revision'] !== ($setting->revision ?? 0)) {
                throw ValidationException::withMessages(['revision' => 'Availability changed. Reload and try again.']);
            }
            $state = $availability->state($member, $workspaceId);
            $timezone = $data['timezone'] ?? $state['timezone'];
            $hours = $availability->validateHours($data['weekly_hours'] ?? $state['weekly_hours'], $timezone, $data['mode'] === 'scheduled');
            Setting::updateOrCreate(['user_id' => $member->id, 'workspace_id' => $workspaceId], [
                'mode' => $data['mode'], 'timezone' => $timezone, 'weekly_hours' => $hours,
                'revision' => ($setting->revision ?? 0) + 1,
            ]);
        });

        return response()->json([...$availability->state($member, $workspaceId), 'member_id' => $member->id, 'can_edit' => true]);
    }
}
