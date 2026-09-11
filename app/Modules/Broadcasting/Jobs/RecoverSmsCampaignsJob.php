<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RecoverSmsCampaignsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 3600;

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('recover-sms-campaigns'))->dontRelease()->expireAfter(55)];
    }

    public function handle(): void
    {
        Campaign::whereIn('channel', ['sms', 'whatsapp'])
            ->where('status', 'preparing')
            ->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn ($id) => PrepareSmsCampaignAudienceJob::dispatch((int) $id)->onQueue('broadcast'));

        Campaign::whereIn('channel', ['sms', 'whatsapp'])
            ->whereIn('status', ['sending', 'retrying'])
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn ($id) => PumpSmsCampaignJob::dispatch((int) $id)->onQueue('broadcast'));

        Campaign::where('channel', 'sms')
            ->where('status', 'waiting_capacity')
            ->orderBy('schedule_at')
            ->orderBy('id')
            ->limit(25)
            ->pluck('id')
            ->each(fn ($id) => LaunchCampaignJob::dispatch((int) $id)->onQueue('broadcast'));
    }
}
