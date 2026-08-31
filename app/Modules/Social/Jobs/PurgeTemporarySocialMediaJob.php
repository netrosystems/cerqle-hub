<?php

namespace App\Modules\Social\Jobs;

use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PurgeTemporarySocialMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        Media::query()
            ->where('is_temporary', true)
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->whereDoesntHave('socialPosts', fn ($query) => $query->whereNotIn('status', ['published']))
            ->orderBy('id')
            ->chunkById(100, function ($media): void {
                foreach ($media as $item) {
                    try {
                        $item->delete();
                    } catch (\Throwable $e) {
                        Log::warning('Temporary social media cleanup failed.', [
                            'media_id' => $item->id,
                            'disk' => $item->disk,
                            'path' => $item->path,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }
}
