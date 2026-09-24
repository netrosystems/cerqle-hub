<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Shared\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileStatsController extends WorkspaceScopedController
{
    private const OMNI_CHANNELS = ['whatsapp', 'instagram', 'messenger', 'webchat'];

    /**
     * GET /api/v1/mobile/stats
     * Workspace-wide omni-channel conversation statistics without pagination.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceId($request);
        $baseQuery = $this->conversationQuery($workspaceId);

        $totals = (clone $baseQuery)
            ->selectRaw('COUNT(*) as total_conversations')
            ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_conversations")
            ->selectRaw('COALESCE(SUM(unread_count), 0) as unread_messages')
            ->selectRaw('SUM(CASE WHEN unread_count > 0 THEN 1 ELSE 0 END) as unread_conversations')
            ->selectRaw('SUM(CASE WHEN assigned_user_id IS NOT NULL THEN 1 ELSE 0 END) as assigned_conversations')
            ->selectRaw("SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_conversations")
            ->first();

        $channels = Conversation::query()
            ->join('channel_accounts', 'channel_accounts.id', '=', 'conversations.channel_account_id')
            ->where('conversations.workspace_id', $workspaceId)
            ->where('channel_accounts.workspace_id', $workspaceId)
            ->whereIn('channel_accounts.channel', self::OMNI_CHANNELS)
            ->groupBy('channel_accounts.channel')
            ->orderBy('channel_accounts.channel')
            ->selectRaw('channel_accounts.channel, COUNT(*) as total')
            ->pluck('total', 'channel')
            ->map(fn ($total) => (int) $total);

        return response()->json([
            'data' => [
                'total_conversations' => (int) ($totals?->total_conversations ?? 0),
                'open' => (int) ($totals?->open_conversations ?? 0),
                'unread' => (int) ($totals?->unread_messages ?? 0),
                'unread_conversations' => (int) ($totals?->unread_conversations ?? 0),
                'assigned' => (int) ($totals?->assigned_conversations ?? 0),
                'resolved' => (int) ($totals?->resolved_conversations ?? 0),
                'channels' => (object) $channels->all(),
            ],
        ]);
    }

    /** @return Builder<Conversation> */
    private function conversationQuery(int $workspaceId): Builder
    {
        return Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('channelAccount', fn ($account) => $account
                ->where('workspace_id', $workspaceId)
                ->whereIn('channel', self::OMNI_CHANNELS));
    }
}
