<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->isAccessibleBy($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->canManage($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->canManage($user, $workspace);
    }

    private function canManage(User $user, Workspace $workspace): bool
    {
        if ((int) $workspace->owner_id === (int) $user->id) {
            return true;
        }

        return $user->isClientAdministrator()
            && $user->client_id !== null
            && (int) $workspace->client_id === (int) $user->client_id;
    }
}
