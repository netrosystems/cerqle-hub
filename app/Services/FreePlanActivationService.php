<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FreePlanActivationService
{
    public function activate(User $user, Plan $plan, string $cycle = 'month'): Subscription
    {
        if (! $user->isClientAdministrator()) {
            throw ValidationException::withMessages([
                'plan_id' => __('Only a client administrator can select a plan.'),
            ]);
        }

        if (! $plan->enabled || ! $plan->isFree()) {
            throw ValidationException::withMessages([
                'plan_id' => __('The selected free plan is unavailable.'),
            ]);
        }

        return DB::transaction(function () use ($user, $plan, $cycle): Subscription {
            if ($user->client_id) {
                Client::query()->lockForUpdate()->findOrFail($user->client_id);
            } else {
                User::query()->lockForUpdate()->findOrFail($user->id);
            }

            if ($user->fresh()->effectiveSubscription()) {
                throw ValidationException::withMessages([
                    'plan_id' => __('An active subscription already exists.'),
                ]);
            }

            return Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => in_array($cycle, ['month', 'year'], true) ? $cycle : 'month',
                'starts_at' => now(),
                'gateway' => 'free',
            ]);
        });
    }
}
