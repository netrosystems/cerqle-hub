<?php

namespace App\Services;

use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;

class ClientAccessService
{
    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const NO_PLAN = 'no_plan';

    public const UNVERIFIED = 'unverified';

    public function state(User $user): string
    {
        if (! $user->hasVerifiedEmail()) {
            return self::UNVERIFIED;
        }

        if (! $this->enforcementEnabled()) {
            return self::ACTIVE;
        }

        if ($user->effectiveSubscription()?->isActive()) {
            return self::ACTIVE;
        }

        return $this->hasSubscriptionHistory($user) ? self::EXPIRED : self::NO_PLAN;
    }

    public function stateForWorkspace(int $workspaceId): string
    {
        if (! $this->enforcementEnabled()) {
            return self::ACTIVE;
        }

        $workspace = Workspace::with(['owner', 'client.users'])->find($workspaceId);
        $owner = $workspace?->client?->users
            ?->first(fn (User $candidate) => $candidate->isClientAdministrator())
            ?? $workspace?->owner;

        if (! $owner instanceof User) {
            return self::NO_PLAN;
        }

        if ($owner->effectiveSubscription()?->isActive()) {
            return self::ACTIVE;
        }

        return $this->hasSubscriptionHistory($owner) ? self::EXPIRED : self::NO_PLAN;
    }

    public function allowsOperationalRead(User $user): bool
    {
        return in_array($this->state($user), [self::ACTIVE, self::EXPIRED], true);
    }

    public function allowsOperationalWrite(User $user): bool
    {
        return $this->state($user) === self::ACTIVE;
    }

    public function allowsWorkspaceWrite(int $workspaceId): bool
    {
        return $this->stateForWorkspace($workspaceId) === self::ACTIVE;
    }

    public function payload(User $user): array
    {
        $state = $this->state($user);

        return [
            'state' => $state,
            'email_verified' => $user->hasVerifiedEmail(),
            'has_active_subscription' => $state === self::ACTIVE,
            'read_only' => $state === self::EXPIRED,
            'can_select_plan' => $user->isClientAdministrator(),
            'pricing_url' => route('client.pricing'),
        ];
    }

    private function hasSubscriptionHistory(User $user): bool
    {
        if ($user->client_id) {
            if (ClientSubscription::where('client_id', $user->client_id)->exists()) {
                return true;
            }

            $clientUserIds = $user->client?->users()->select('id');

            return $clientUserIds !== null
                && Subscription::whereIn('user_id', $clientUserIds)->exists();
        }

        return $user->subscriptions()->exists();
    }

    private function enforcementEnabled(): bool
    {
        // Test suites may opt out while migrating legacy fixtures. This cannot
        // disable billing enforcement in local, staging, or production.
        return ! app()->environment('testing')
            || (bool) config('saas.enforce_client_subscription', true);
    }
}
