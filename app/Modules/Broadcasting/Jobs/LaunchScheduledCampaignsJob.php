<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class LaunchScheduledCampaignsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Keep a worker outage from adding one identical scanner job per minute.
    // Normal runs release this lock as soon as the scan completes.
    public int $uniqueFor = 3600;

    public function handle(): void
    {
        Campaign::where('status', 'queued')
            ->whereNotNull('schedule_at')
            ->where('schedule_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($campaigns) {
                $campaigns->each(
                    fn (Campaign $campaign) => LaunchCampaignJob::dispatch($campaign->id)->onQueue('broadcast')
                );
            });
    }
}
