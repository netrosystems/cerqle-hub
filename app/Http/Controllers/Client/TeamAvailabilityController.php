<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateMemberAvailabilityRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use Illuminate\Http\JsonResponse;

class TeamAvailabilityController extends Controller
{
    public function update(UpdateMemberAvailabilityRequest $request, User $member, Workspace $workspace): JsonResponse
    {
        $actor = $request->user();
        abort_unless($workspace->client_id === $actor->client_id && $workspace->isAccessibleBy($actor), 404);
        abort_unless($member->client_id === $actor->client_id && $workspace->isAccessibleBy($member), 404);
        $data = $request->validated();

        $availability = WorkspaceMemberAvailability::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $member->id],
            ['enabled' => (bool) $data['enabled'], 'timezone' => $data['timezone'] ?? 'UTC', 'schedule_json' => $data['schedule'] ?? []],
        );

        return response()->json(['availability' => $this->payload($availability)]);
    }

    /** @return array{enabled:bool,timezone:string,schedule:array<string,mixed>} */
    private function payload(WorkspaceMemberAvailability $availability): array
    {
        return [
            'enabled' => $availability->enabled,
            'timezone' => $availability->timezone,
            'schedule' => $availability->schedule_json ?? [],
        ];
    }
}
