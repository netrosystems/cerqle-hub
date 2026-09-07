<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmailAccountLimitService
{
    /** Provider calls must finish before entering this short transaction. */
    public function save(array $identity, array $attributes): ChannelAccount
    {
        $workspace = Workspace::findOrFail($identity['workspace_id']);

        return DB::transaction(function () use ($identity, $attributes, $workspace) {
            // Acquire the billing-account lock before any snapshot reads in this
            // transaction, including on MySQL's REPEATABLE READ isolation level.
            $usage = $this->usage($workspace, true);
            $existing = ChannelAccount::where($identity)->first();
            if (! $existing && ! $usage['can_connect']) {
                throw ValidationException::withMessages([
                    'connection' => __('Your plan allows :limit connected email accounts across all workspaces. Disconnect an unused mailbox or upgrade your plan.', ['limit' => $usage['limit']]),
                ]);
            }

            return ChannelAccount::updateOrCreate($identity, $attributes);
        });
    }

    public function usage(Workspace $workspace, bool $lock = false): array
    {
        if ($workspace->client_id) {
            $account = Client::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($workspace->client_id);
            $plan = $account->effectivePlan();
            $workspaces = Workspace::where('client_id', $account->id)->select('id');
        } else {
            // Members draw from the workspace owner's subscription, not their own.
            $account = User::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($workspace->owner_id);
            $plan = $account->effectiveSubscription()?->plan;
            $workspaces = Workspace::whereNull('client_id')->where('owner_id', $account->id)->select('id');
        }
        $value = $plan?->limitValue('email_accounts');
        // Preserve legacy plans; an account without a plan has no allowance.
        $limit = ! $plan ? 0 : ($value === null ? null : (is_numeric($value) ? max(0, (int) $value) : 0));
        // Expired tokens still occupy a connection slot until disconnected.
        $used = ChannelAccount::where('channel', 'email')->whereIn('workspace_id', $workspaces)->count();

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'unlimited' => $limit === null,
            'can_connect' => $limit === null || $used < $limit,
        ];
    }
}
