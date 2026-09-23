<?php

namespace App\Modules\Inbox\Services;

use App\Models\User;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\DB;

class EmailBulkResolveService
{
    public function resolve(int $workspaceId, ?int $accountId = null, ?User $actor = null): int
    {
        abort_unless($workspaceId > 0, 403);
        if ($accountId !== null) {
            ChannelAccount::where('workspace_id', $workspaceId)->where('channel', 'email')->findOrFail($accountId);
        }

        $query = Conversation::where('workspace_id', $workspaceId)
            ->where('status', 'open')
            ->whereHas('channelAccount', fn ($query) => $query->where('workspace_id', $workspaceId)->where('channel', 'email'))
            ->when($accountId !== null, fn ($query) => $query->where('channel_account_id', $accountId));

        if (! $actor) {
            $resolvedAt = now()->format('Y-m-d H:i:s');

            return $query->update([
                'status' => 'resolved',
                'resolved_at' => DB::raw("COALESCE(resolved_at, '{$resolvedAt}')"),
            ]);
        }

        $count = 0;
        $query->select('id', 'workspace_id')->chunkById(100, function ($conversations) use ($actor, &$count): void {
            foreach ($conversations as $conversation) {
                if (app(ConversationActivityService::class)->status($conversation, 'resolved', $actor)->status === 'resolved') {
                    $count++;
                }
            }
        });

        return $count;
    }
}
