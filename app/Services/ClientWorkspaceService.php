<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;

class ClientWorkspaceService
{
    /**
     * Ensure the client has at least one workspace (default: client name, no owner until a user is linked).
     */
    public function ensureWorkspaceExists(Client $client): Workspace
    {
        $existing = $client->workspaces()->orderBy('id')->first();
        if ($existing) {
            return $existing;
        }

        return Workspace::create([
            'client_id' => $client->id,
            'name' => $client->name,
            'default_locale' => 'en',
            'currency_code' => $client->base_currency,
        ]);
    }

    /**
     * Initialise a client-scoped user without widening their access.
     *
     * Workspace assignments are deliberate: client administrators select them
     * when adding/inviting a member. This method only preserves an existing
     * active assignment, or makes the first administrator the owner of the
     * orphan default workspace.
     */
    public function syncClientUser(User $user): void
    {
        if (! $user->client_id) {
            return;
        }

        $client = $user->client;
        if (! $client) {
            return;
        }

        $this->ensureWorkspaceExists($client);

        $workspaces = $client->workspaces()->orderBy('id')->get();

        if ($user->isClientAdministrator()) {
            $orphan = $workspaces->firstWhere('owner_id', null);
            if ($orphan) {
                $orphan->forceFill(['owner_id' => $user->id])->saveQuietly();
                if (! $orphan->members()->where('user_id', $user->id)->exists()) {
                    $orphan->members()->attach($user->id, ['role' => 'owner']);
                }
            }
        }

        if ($user->workspace_id) {
            $activeWorkspace = $workspaces->firstWhere('id', $user->workspace_id);
            if ($activeWorkspace && ! $activeWorkspace->isAccessibleBy($user)) {
                $activeWorkspace->members()->syncWithoutDetaching([
                    $user->id => ['role' => $activeWorkspace->owner_id === $user->id ? 'owner' : 'staff'],
                ]);
            }
        }

        $user->refresh();

        if (! $user->workspace_id) {
            $primary = $user->accessibleWorkspaces()->first();
            if ($primary) {
                $user->forceFill(['workspace_id' => $primary->id])->saveQuietly();
            }
        }
    }
}
