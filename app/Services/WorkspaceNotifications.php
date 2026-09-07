<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class WorkspaceNotifications
{
    /** @return MorphMany<DatabaseNotification, User> */
    public static function forRequest(Request $request): MorphMany
    {
        $user = $request->user();
        $workspaceId = $request->hasSession()
            ? $request->session()->get('current_workspace_id', $user->workspace_id)
            : $user->workspace_id;

        return self::forUser($user, (int) $workspaceId);
    }

    /** @return MorphMany<DatabaseNotification, User> */
    public static function forUser(User $user, ?int $workspaceId): MorphMany
    {
        $query = $user->notifications();
        if (! $workspaceId || ! Workspace::find($workspaceId)?->isAccessibleBy($user)) {
            return $query->whereRaw('1 = 0');
        }

        // Missing scope is never treated as a universal notification.
        return $query->where('data->workspace_id', $workspaceId);
    }
}
