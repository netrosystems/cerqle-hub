<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Inbox\Models\WorkspaceMemberAvailability;
use Carbon\CarbonInterface;

class TeamAvailabilityService
{
    public function __construct(private readonly WeeklySchedule $weekly) {}

    public function isAvailable(int $workspaceId, User|int $user, ?CarbonInterface $at = null): bool
    {
        $user = $user instanceof User ? $user : User::find($user);
        if (! $user || ! $user->isActive() || ! User::inWorkspace($workspaceId)->whereKey($user->id)->exists()) {
            return false;
        }

        $availability = WorkspaceMemberAvailability::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->first();

        if (! $availability || ! $availability->enabled) {
            return true;
        }

        return $this->weekly->contains([
            'enabled' => true,
            'timezone' => $availability->timezone,
            'schedule' => $availability->schedule_json ?? [],
        ], $at);
    }
}
