<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\CampaignStep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class PumpSmsCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $campaignId) {}

    public function middleware(): array
    {
        // expireAfter(30) keeps a wedged worker from holding the lock for two
        // minutes. The recover job re-dispatches us every minute if needed.
        return [(new WithoutOverlapping('sms-pump:'.$this->campaignId))->releaseAfter(1)->expireAfter(30)];
    }

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || ! in_array($campaign->status, ['sending', 'retrying'], true)) {
            return;
        }

        $claimTimeout = max(60, (int) config('broadcasting.sms.claim_timeout_seconds', 180));
        CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'dispatching')
            ->where('claimed_at', '<', now()->subSeconds($claimTimeout))
            ->update([
                'status' => 'retrying',
                'next_attempt_at' => now()->addSeconds(min(60, $claimTimeout)),
                'failure_class' => 'stale_claim',
                'failed_reason' => 'Recovered after a worker stopped before sending.',
            ]);

        $step = $campaign->steps()->where('status', 'active')->orderBy('position')->first();
        if (! $step) {
            $step = $campaign->steps()->where('status', 'pending')->orderBy('position')->first();
            if (! $step) {
                FinalizeCampaignJob::dispatch($campaign->id)->onQueue('broadcast');

                return;
            }
            if ($step->scheduled_at?->isFuture()) {
                $this->dispatchAgain($campaign, min(60, max(1, now()->diffInSeconds($step->scheduled_at))));

                return;
            }
            $step->update(['status' => 'active', 'started_at' => now()]);
        }

        $ids = $this->claimDueRecipients($campaign, $step);
        foreach ($ids as $recipientId) {
            SendSmsCampaignMessageJob::dispatch($recipientId)->onQueue('broadcast');
        }

        if ($ids !== []) {
            $campaign->update(['status' => 'sending', 'pause_reason' => null]);
            // Re-arm on a sub-second tick so the buffer drains at provider rate.
            // A worker freeing a slot triggers a fresh claim within 200 ms.
            $this->dispatchAgain($campaign, 1);

            return;
        }

        // The pool is fully settled: every queued recipient has been claimed at
        // least once. We can advance the step even if some retries are still in
        // flight — they continue in the background and feed the finaliser.
        $stillQueuedOrInFlight = CampaignRecipient::where('campaign_step_id', $step->id)
            ->whereIn('status', ['queued', 'dispatching', 'sending'])
            ->exists();

        if ($stillQueuedOrInFlight) {
            $campaign->update(['status' => 'sending']);
            // Workers may be near completion; re-check in one second rather
            // than waiting on the retry horizon.
            $this->dispatchAgain($campaign, 1);

            return;
        }

        $hasPendingRetries = CampaignRecipient::where('campaign_step_id', $step->id)
            ->where('status', 'retrying')
            ->exists();

        $step->update(['status' => 'completed', 'completed_at' => now()]);

        /** @var CampaignStep|null $next */
        $next = $campaign->steps()->where('status', 'pending')->orderBy('position')->first();
        if (! $next) {
            if ($hasPendingRetries) {
                $campaign->update(['status' => 'retrying', 'pause_reason' => null]);
                $this->dispatchAgain($campaign, $this->secondsUntilNextRetry($step));
            } else {
                FinalizeCampaignJob::dispatch($campaign->id)->onQueue('broadcast');
            }

            return;
        }

        $delay = max(0, (int) $next->delay_after_previous_seconds);
        $next->update(['scheduled_at' => now()->addSeconds($delay)]);
        $campaign->update([
            'status' => 'sending',
            'pause_reason' => $delay > 0 ? "Waiting {$delay} seconds before {$next->name}." : null,
        ]);
        $this->dispatchAgain($campaign, max(1, min(60, $delay)));
    }

    /** @return array<int, int> */
    private function claimDueRecipients(Campaign $campaign, CampaignStep $step): array
    {
        // Keep at least two seconds of work available at the active step's
        // configured speed. An old environment value such as 25 would
        // otherwise cap a 180 TPS campaign at roughly 25 sends/second because
        // the pump runs once per second.
        $targetRate = max(1, (int) $step->rate_per_second);
        $configuredBuffer = max(1, (int) config('broadcasting.sms.dispatch_buffer', 360));
        $buffer = $configuredBuffer >= $targetRate
            ? $configuredBuffer
            : max($configuredBuffer, $targetRate * 2);

        return DB::transaction(function () use ($campaign, $step, $buffer) {
            // In-flight workers occupy dispatching or sending. Count without an
            // artificial LIMIT — the previous code capped the count at the
            // buffer which masked the real occupancy and made the buffer feel
            // permanently full.
            $inFlight = (int) CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('campaign_step_id', $step->id)
                ->whereIn('status', ['dispatching', 'sending'])
                ->lockForUpdate()
                ->count();

            $available = max(0, $buffer - $inFlight);
            if ($available === 0) {
                return [];
            }

            // Prioritise freshly queued recipients over retrying ones. Without
            // this, the retrying rows (which carry the lowest IDs because they
            // were claimed first) monopolise the buffer and starve the rest
            // of the step. Once queued is empty, fall through to due retries.
            $queuedIds = $this->claimWhere(
                $campaign,
                $step,
                $available,
                fn ($q) => $q->where('status', 'queued'),
            );

            $remaining = $available - count($queuedIds);
            if ($remaining <= 0) {
                return $queuedIds;
            }

            $retryIds = $this->claimWhere(
                $campaign,
                $step,
                $remaining,
                fn ($q) => $q->where('status', 'retrying')->where('next_attempt_at', '<=', now()),
            );

            return array_merge($queuedIds, $retryIds);
        }, 5);
    }

    /**
     * @param  \Closure(\Illuminate\Database\Query\Builder): void  $constraint
     * @return array<int, int>
     */
    private function claimWhere(Campaign $campaign, CampaignStep $step, int $limit, \Closure $constraint): array
    {
        $query = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('campaign_step_id', $step->id)
            ->where($constraint)
            ->orderBy('id')
            ->limit($limit);

        $ids = $query->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids !== []) {
            CampaignRecipient::whereIn('id', $ids)
                ->whereIn('status', ['queued', 'retrying'])
                ->update(['status' => 'dispatching', 'claimed_at' => now()]);
        }

        return $ids;
    }

    private function secondsUntilNextRetry(CampaignStep $step): int
    {
        $next = CampaignRecipient::where('campaign_step_id', $step->id)
            ->where('status', 'retrying')
            ->whereNotNull('next_attempt_at')
            ->min('next_attempt_at');

        return $next ? min(60, max(1, now()->diffInSeconds($next))) : 2;
    }

    private function dispatchAgain(Campaign $campaign, int $seconds): void
    {
        self::dispatch($campaign->id)
            ->onQueue('broadcast')
            ->delay(now()->addSeconds(max(1, $seconds)));
    }
}
