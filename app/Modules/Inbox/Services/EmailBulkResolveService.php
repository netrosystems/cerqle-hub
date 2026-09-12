<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\DB;

class EmailBulkResolveService
{
    public function resolve(int $workspaceId, ?int $accountId = null): int
    {
        abort_unless($workspaceId > 0, 403);
        if ($accountId !== null) {
            ChannelAccount::where('workspace_id', $workspaceId)->where('channel', 'email')->findOrFail($accountId);
        }

        $resolvedAt = now()->format('Y-m-d H:i:s');

        // A conditional update preserves concurrent pending/snoozed transitions.
        return Conversation::where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->whereHas('channelAccount', fn ($query) => $query->where('workspace_id', $workspaceId)->where('channel', 'email'))
            ->when($accountId !== null, fn ($query) => $query->where('channel_account_id', $accountId))
            ->update([
                'status' => 'resolved',
                'resolved_at' => DB::raw("COALESCE(resolved_at, '{$resolvedAt}')"),
            ]);
    }
}
