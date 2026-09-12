<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Conversation;
use Illuminate\Support\Facades\DB;

class ConversationDeletionService
{
    public function delete(int $workspaceId, string $uuid): void
    {
        abort_unless($workspaceId > 0, 403);

        DB::transaction(function () use ($workspaceId, $uuid): void {
            $conversation = Conversation::where('workspace_id', $workspaceId)
                ->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            foreach (['internal_notes', 'inbox_notes', 'inbox_assignments', 'inbox_label_conversation', 'widget_push_subscriptions', 'messages'] as $table) {
                DB::table($table)->where('conversation_id', $conversation->id)->delete();
            }
            // Retain metering/billing history without a dangling conversation reference.
            DB::table('ai_runs')->where('conversation_id', $conversation->id)->update(['conversation_id' => null]);
            $conversation->delete();
        });
    }
}
