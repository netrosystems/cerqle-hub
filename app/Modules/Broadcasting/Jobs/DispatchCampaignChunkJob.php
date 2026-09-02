<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Services\ClientAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $campaignId,
        public readonly array $contactIds,
    ) {}

    public function handle(): void
    {
        $access = app(ClientAccessService::class);
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->status === 'failed') {
            return;
        }

        if (! $access->allowsWorkspaceWrite($campaign->workspace_id)) {
            $campaign->update(['status' => 'safety_paused', 'pause_reason' => 'Campaign paused because the subscription is inactive.']);

            return;
        }

        foreach ($this->contactIds as $i => $contactId) {
            SendCampaignMessageJob::dispatch($campaign->id, $contactId)
                ->onQueue('broadcast')
                ->delay(now()->addMilliseconds($i * 100)); // 10 msgs/second rate limit
        }
    }
}
