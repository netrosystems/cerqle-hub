<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reserve before network I/O, refund definitive failures, pool all connected messaging channels. */
class MessagingMessageLimitService
{
    public function usage(Workspace $workspace, bool $lock = false): array
    {
        [$plan] = app(ChannelPlanLimitService::class)->account($workspace, $lock);
        $value = $plan?->limitValue('messaging_messages_per_month');
        $limit = ! $plan ? 0 : ($value === null ? null : max(0, (int) $value));
        $used = (int) DB::table('messaging_quota_periods')->where('account_key', $this->accountKey($workspace))
            ->where('period', (int) now()->format('Ym'))->value('value');

        return app(ChannelPlanLimitService::class)->summary('messaging_messages_per_month', $used, $limit);
    }

    public function send(int $workspaceId, callable $send): mixed
    {
        $workspace = Workspace::findOrFail($workspaceId);
        $accountKey = $this->accountKey($workspace);
        $period = (int) now()->format('Ym');
        DB::transaction(function () use ($workspace, $period, $accountKey) {
            $usage = $this->usage($workspace, true);
            if ($usage['is_full']) {
                throw ValidationException::withMessages([
                    'plan_limit' => __('Monthly messaging limit reached (:used/:limit across all channels and workspaces). Upgrade your plan or wait until next month.', [
                        'used' => $usage['used'], 'limit' => $usage['limit'],
                    ]),
                ]);
            }
            DB::table('messaging_quota_periods')->insertOrIgnore([
                'account_key' => $accountKey, 'period' => $period, 'value' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('messaging_quota_periods')->where('account_key', $accountKey)->where('period', $period)
                ->increment('value', 1, ['updated_at' => now()]);
        }, 3);

        try {
            $result = $send();
        } catch (ConnectionException $e) {
            // Delivery is uncertain on timeout: retain the reservation, never allow unlimited retries.
            throw $e;
        } catch (\Throwable $e) {
            $this->refund($accountKey, $period);
            throw $e;
        }
        if ($result instanceof Response && ! $result->successful()) {
            $this->refund($accountKey, $period);
        }

        return $result;
    }

    private function accountKey(Workspace $workspace): string
    {
        return $workspace->client_id ? 'client:'.$workspace->client_id : 'user:'.$workspace->owner_id;
    }

    private function refund(string $accountKey, int $period): void
    {
        DB::table('messaging_quota_periods')->where('account_key', $accountKey)
            ->where('period', $period)->where('value', '>', 0)->decrement('value', 1, ['updated_at' => now()]);
    }
}
