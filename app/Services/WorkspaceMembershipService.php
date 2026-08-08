<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Applies explicit per-workspace team access without widening access by default. */
class WorkspaceMembershipService
{
    /**
     * @param array<int, array{workspace_id:int|string, role:string}> $assignments
     * @return Collection<int, Workspace>
     */
    public function validateAssignments(Client $client, array $assignments): Collection
    {
        if ($assignments === []) {
            throw ValidationException::withMessages(['workspace_assignments' => __('Select at least one workspace.')]);
        }

        $ids = collect($assignments)->pluck('workspace_id')->map(fn ($id) => (int) $id);
        if ($ids->contains(fn (int $id) => $id <= 0) || $ids->unique()->count() !== $ids->count()) {
            throw ValidationException::withMessages(['workspace_assignments' => __('Each workspace can only be selected once.')]);
        }

        foreach ($assignments as $assignment) {
            if (! in_array($assignment['role'] ?? null, ['administrator', 'staff'], true)) {
                throw ValidationException::withMessages(['workspace_assignments' => __('Each workspace needs a valid role.')]);
            }
        }

        $workspaces = Workspace::where('client_id', $client->id)
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        if ($workspaces->count() !== $ids->count()) {
            throw ValidationException::withMessages(['workspace_assignments' => __('You can only assign workspaces in your organization.')]);
        }

        return $workspaces;
    }

    /** @param array<int, array{workspace_id:int|string, role:string}> $assignments */
    public function sync(User $member, Client $client, array $assignments): void
    {
        $workspaces = $this->validateAssignments($client, $assignments);

        DB::transaction(function () use ($member, $client, $assignments, $workspaces): void {
            // Serialize seat checks per workspace so concurrent administrators
            // cannot both consume the last available plan seat.
            $workspaces = Workspace::query()
                ->whereIn('id', $workspaces->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $desired = collect($assignments)->mapWithKeys(fn (array $assignment) => [
                (int) $assignment['workspace_id'] => ['role' => $assignment['role']],
            ]);
            $currentIds = $member->workspaces()
                ->where('client_id', $client->id)
                ->pluck('workspaces.id')
                ->map(fn ($id) => (int) $id);
            $ownedIds = Workspace::query()
                ->where('client_id', $client->id)
                ->where('owner_id', $member->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

            $limit = $client->effectivePlan()?->limits['users'] ?? null;
            foreach ($workspaces as $workspace) {
                if ($currentIds->contains($workspace->id) || $workspace->owner_id === $member->id || $limit === null) {
                    continue;
                }

                $seats = $workspace->members()->count();
                if ($workspace->owner_id && ! $workspace->members()->whereKey($workspace->owner_id)->exists()) {
                    ++$seats;
                }
                if ($seats >= (int) $limit) {
                    throw ValidationException::withMessages(['workspace_assignments' => __(
                        ':workspace has reached its :limit-user plan limit.',
                        ['workspace' => $workspace->name, 'limit' => $limit],
                    )]);
                }
            }

            // Ownership is a permanent workspace assignment until ownership is
            // explicitly transferred. Do not let a normal team edit detach it.
            $remove = $currentIds->diff($desired->keys())->diff($ownedIds);
            if ($remove->isNotEmpty()) {
                $member->workspaces()->detach($remove->all());
            }

            foreach ($desired as $workspaceId => $attributes) {
                $workspace = $workspaces->get((int) $workspaceId);
                if ($workspace && $workspace->owner_id !== $member->id) {
                    $member->workspaces()->syncWithoutDetaching([$workspaceId => $attributes]);
                }
            }

            $member->refresh();
            $activeWorkspace = $member->workspace_id ? Workspace::find($member->workspace_id) : null;
            if (! $activeWorkspace || ! $activeWorkspace->isAccessibleBy($member)) {
                $fallback = $member->accessibleWorkspaces()->first();
                $member->forceFill(['workspace_id' => $fallback?->id])->saveQuietly();
            }
        });
    }
}
