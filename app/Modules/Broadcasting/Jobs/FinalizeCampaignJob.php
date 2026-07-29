<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Events\CampaignCompleted;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Services\SmsCampaignCapacityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Finalises a campaign once all recipient sends have settled.
 *
 * Counts recipients still in the `queued` bucket; if any remain, it
 * re-schedules itself a minute later. Otherwise, marks the campaign
 * as `completed`, refreshes totals, and fires CampaignCompleted.
 */
class FinalizeCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $campaignId,
    ) {}

    public function handle(SmsCampaignCapacityService $capacity): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        // If user paused the campaign, don't auto-complete; wait for resume.
        if ($campaign->status === 'paused') {
            return;
        }

        // Already finalised
        if (in_array($campaign->status, ['completed', 'completed_with_failures', 'failed', 'cancelled', 'draft'], true)) {
            $campaign->updateTotals();

            return;
        }

        $stillQueued = CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['queued', 'dispatching', 'sending', 'retrying'])
            ->count();

        if ($stillQueued > 0) {
            // A deliberately slow million-recipient campaign can run for days.
            // Never finalise it while recipients remain unsettled.
            self::dispatch($campaign->id)
                ->onQueue('broadcast')
                ->delay(now()->addSeconds(60));

            return;
        }

        $totals = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $sent = (int) ($totals['sent'] ?? 0)
            + (int) ($totals['delivered'] ?? 0)
            + (int) ($totals['read'] ?? 0);
        $failed = (int) ($totals['failed'] ?? 0);
        $total = $sent + $failed + (int) ($totals['queued'] ?? 0);

        $newStatus = match (true) {
            $total === 0 || ($failed > 0 && $sent === 0) => 'failed',
            $failed > 0 => 'completed_with_failures',
            default => 'completed',
        };

        // Atomic guard: only one concurrent worker may finalize the campaign.
        // If another worker already set the status, affected=0 and we skip the event.
        $affected = Campaign::where('id', $campaign->id)
            ->whereNotIn('status', ['completed', 'completed_with_failures', 'failed', 'cancelled', 'draft'])
            ->update(['status' => $newStatus, 'completed_at' => now(), 'pause_reason' => null]);

        if ($affected === 0) {
            return;
        }

        $campaign->updateTotals();
        $campaign->steps()->whereNotIn('status', ['completed'])->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        if ($campaign->channel === 'sms') {
            $capacity->release($campaign);
        }

        CampaignCompleted::dispatch($campaign->fresh());
    }
}
