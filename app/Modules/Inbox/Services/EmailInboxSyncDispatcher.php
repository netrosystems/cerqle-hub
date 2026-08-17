<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Support\Facades\Cache;

class EmailInboxSyncDispatcher
{
    /**
     * Queue provider refreshes for active email accounts without allowing
     * multiple browsers or mobile devices to multiply provider traffic.
     */
    public function dispatchForWorkspace(int $workspaceId, ?int $accountId = null, int $throttleSeconds = 10): int
    {
        $queued = 0;

        ChannelAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('channel', 'email')
            ->where('status', 'active')
            ->when($accountId, fn ($query) => $query->whereKey($accountId))
            ->pluck('id')
            ->each(function (int $id) use ($workspaceId, $throttleSeconds, &$queued): void {
                $lockKey = "email-inbox:provider-sync:{$workspaceId}:{$id}";

                if (Cache::add($lockKey, true, now()->addSeconds($throttleSeconds))) {
                    SyncEmailAccountJob::dispatch($id)->onQueue('default');
                    $queued++;
                }
            });

        return $queued;
    }
}
