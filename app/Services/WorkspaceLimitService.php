<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Enforces the plan's organization-wide workspace allowance. */
class WorkspaceLimitService
{
    /**
     * Create a workspace while serializing the limit check for the organization.
     *
     * Locking the client (or standalone user) prevents concurrent requests from
     * both consuming the final available workspace slot.
     *
     * @param  array{name:string}  $attributes
     */
    public function createFor(User $user, array $attributes): Workspace
    {
        return DB::transaction(function () use ($user, $attributes): Workspace {
            if ($user->client_id) {
                $client = Client::query()->lockForUpdate()->findOrFail($user->client_id);
                $limit = $this->normalizeLimit($client->effectivePlan()?->limitValue('workspaces'));
                $count = Workspace::query()->where('client_id', $client->id)->count();

                $this->ensureCapacity($limit, $count);

                return $this->createWorkspace($user, $attributes, $client->id);
            }

            // Standalone accounts have no client row to lock, so serialize on the
            // owner record and apply any effective self-serve subscription plan.
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $limit = $this->normalizeLimit($lockedUser->effectiveSubscription()?->plan?->limitValue('workspaces'));
            $count = Workspace::query()->where('owner_id', $lockedUser->id)->whereNull('client_id')->count();

            $this->ensureCapacity($limit, $count);

            return $this->createWorkspace($lockedUser, $attributes, null);
        });
    }

    /** @return array{limit:?int,count:int,remaining:?int,can_create:bool} */
    public function usageFor(User $user): array
    {
        if ($user->client_id) {
            $client = Client::query()->find($user->client_id);
            $limit = $this->normalizeLimit($client?->effectivePlan()?->limitValue('workspaces'));
            $count = Workspace::query()->where('client_id', $user->client_id)->count();
        } else {
            $limit = $this->normalizeLimit($user->effectiveSubscription()?->plan?->limitValue('workspaces'));
            $count = Workspace::query()->where('owner_id', $user->id)->whereNull('client_id')->count();
        }

        return [
            'limit' => $limit,
            'count' => $count,
            'remaining' => $limit === null ? null : max(0, $limit - $count),
            'can_create' => $limit === null || $count < $limit,
        ];
    }

    private function normalizeLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function ensureCapacity(?int $limit, int $count): void
    {
        if ($limit === null || $count < $limit) {
            return;
        }

        $allowance = trans_choice(
            'Your plan allows up to :count workspace.|Your plan allows up to :count workspaces.',
            $limit,
            ['count' => $limit],
        );

        throw ValidationException::withMessages([
            'name' => $allowance.' '.__('Upgrade your plan to create another.'),
        ]);
    }

    /** @param  array{name:string}  $attributes */
    private function createWorkspace(User $user, array $attributes, ?int $clientId): Workspace
    {
        $workspace = Workspace::create([
            'name' => $attributes['name'],
            'owner_id' => $user->id,
            'client_id' => $clientId,
            'default_locale' => $user->locale ?? 'en',
            'currency_code' => $user->display_currency,
        ]);

        $workspace->members()->attach($user->id, ['role' => 'owner']);

        return $workspace;
    }
}
