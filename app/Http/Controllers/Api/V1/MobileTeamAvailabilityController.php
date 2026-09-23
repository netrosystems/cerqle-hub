<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdateMemberAvailabilityRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileTeamAvailabilityController extends WorkspaceScopedController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isClientAdministrator(), 403);
        $workspace = Workspace::findOrFail($this->workspaceId($request));
        $members = $workspace->members()->where('users.status', User::STATUS_ACTIVE)->get()
            ->push($workspace->owner)->filter()->unique('id')->values();
        $availability = WorkspaceMemberAvailability::query()
            ->where('workspace_id', $workspace->id)->whereIn('user_id', $members->pluck('id'))->get()->keyBy('user_id');

        return response()->json(['data' => $members->map(fn (User $member) => [
            'id' => $member->id,
            'name' => $member->name,
            'avatar' => $member->avatar,
            'availability' => $this->payload($availability->get($member->id)),
        ])]);
    }

    public function update(UpdateMemberAvailabilityRequest $request, int $member): JsonResponse
    {
        $workspace = Workspace::findOrFail($this->workspaceId($request));
        $user = User::findOrFail($member);
        abort_unless($workspace->isAccessibleBy($user) && $user->client_id === $request->user()->client_id, 404);
        $data = $request->validated();
        $availability = WorkspaceMemberAvailability::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['enabled' => (bool) $data['enabled'], 'timezone' => $data['timezone'] ?? 'UTC', 'schedule_json' => $data['schedule'] ?? []],
        );

        return response()->json(['availability' => $this->payload($availability)]);
    }

    /** @return array{enabled:bool,timezone:string,schedule:array<string,mixed>} */
    private function payload(?WorkspaceMemberAvailability $availability): array
    {
        return $availability ? [
            'enabled' => $availability->enabled,
            'timezone' => $availability->timezone,
            'schedule' => $availability->schedule_json ?? [],
        ] : ['enabled' => false, 'timezone' => 'UTC', 'schedule' => []];
    }
}
