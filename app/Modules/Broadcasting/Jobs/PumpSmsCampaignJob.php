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
        return [(new WithoutOverlapping('sms-pump:'.$this->campaignId))->releaseAfter(1)->expireAfter(120)];
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
                'next_attempt_at' => now(),
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
            $this->dispatchAgain($campaign, 1);

            return;
        }

        $unsettled = CampaignRecipient::where('campaign_step_id', $step->id)
            ->whereIn('status', ['queued', 'dispatching', 'sending', 'retrying'])
            ->exists();

        if ($unsettled) {
            $onlyRetries = ! CampaignRecipient::where('campaign_step_id', $step->id)
                ->whereIn('status', ['queued', 'dispatching', 'sending'])
                ->exists();
            $campaign->update(['status' => $onlyRetries ? 'retrying' : 'sending']);
            $this->dispatchAgain($campaign, $this->secondsUntilNextRetry($step));

            return;
        }

        $step->update(['status' => 'completed', 'completed_at' => now()]);
        /** @var CampaignStep|null $next */
        $next = $campaign->steps()->where('status', 'pending')->orderBy('position')->first();
        if (! $next) {
            FinalizeCampaignJob::dispatch($campaign->id)->onQueue('broadcast');

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
        $buffer = max(1, (int) config('broadcasting.sms.dispatch_buffer', 25));

        return DB::transaction(function () use ($campaign, $step, $buffer) {
            $inFlight = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('campaign_step_id', $step->id)
                ->whereIn('status', ['dispatching', 'sending'])
                ->limit($buffer)
                ->lockForUpdate()
                ->pluck('id')
                ->count();
            $available = max(0, $buffer - $inFlight);
            if ($available === 0) {
                return [];
            }

            $ids = CampaignRecipient::where('campaign_id', $campaign->id)
                ->where('campaign_step_id', $step->id)
                ->where(function ($query) {
                    $query->where('status', 'queued')
                        ->orWhere(function ($retry) {
                            $retry->where('status', 'retrying')
                                ->where('next_attempt_at', '<=', now());
                        });
                })
                ->orderBy('id')
                ->limit($available)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids !== []) {
                CampaignRecipient::whereIn('id', $ids)
                    ->whereIn('status', ['queued', 'retrying'])
                    ->update(['status' => 'dispatching', 'claimed_at' => now()]);
            }

            return $ids;
        }, 5);
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
