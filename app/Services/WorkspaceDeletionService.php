<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Permanently removes a workspace and every record scoped to it. */
class WorkspaceDeletionService
{
    /** Permanently purge a workspace as part of deleting its owning client. */
    public function purgeForClient(Workspace $workspace): void
    {
        $this->deleteDependentRecords($workspace->id);
        $this->deleteWorkspaceScopedRecords($workspace->id);
        $workspace->delete();
    }

    /**
     * Delete the workspace atomically and return the deleting user's next workspace ID.
     */
    public function delete(Workspace $workspace, User $actor): ?int
    {
        return DB::transaction(function () use ($workspace, $actor): ?int {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);

            $siblings = Workspace::query()
                ->when(
                    $workspace->client_id,
                    fn ($query) => $query->where('client_id', $workspace->client_id),
                    fn ($query) => $query->whereNull('client_id')->where('owner_id', $workspace->owner_id),
                )
                ->whereKeyNot($workspace->id)
                ->orderBy('id')
                ->get();

            if ($siblings->isEmpty()) {
                throw ValidationException::withMessages([
                    'workspace' => __('The only workspace cannot be deleted. Create another workspace first.'),
                ]);
            }

            $actorFallback = $actor->accessibleWorkspaces()
                ->first(fn (Workspace $candidate) => $candidate->id !== $workspace->id);
            if ((int) $actor->workspace_id === (int) $workspace->id && ! $actorFallback) {
                if ($actor->isClientAdministrator() && $workspace->client_id) {
                    $actorFallback = $siblings->first();
                    $actorFallback->members()->syncWithoutDetaching([
                        $actor->id => ['role' => 'administrator'],
                    ]);
                } else {
                    throw ValidationException::withMessages([
                        'workspace' => __('Assign yourself to another workspace before deleting your active workspace.'),
                    ]);
                }
            }

            $affectedUsers = User::query()->where('workspace_id', $workspace->id)->get();
            foreach ($affectedUsers as $user) {
                $fallback = $user->accessibleWorkspaces()
                    ->first(fn (Workspace $candidate) => $candidate->id !== $workspace->id);
                $user->forceFill(['workspace_id' => $fallback?->id])->saveQuietly();
            }

            $actor->refresh();
            $actorFallbackId = $actor->workspace_id;

            $this->deleteDependentRecords($workspace->id);
            $this->deleteWorkspaceScopedRecords($workspace->id);
            $workspace->delete();

            return $actorFallbackId;
        });
    }

    /** Delete records in tables that inherit workspace scope through a parent record. */
    private function deleteDependentRecords(int $workspaceId): void
    {
        $this->deleteWhereIn('automation_run_logs', 'run_id', 'automation_runs', 'id', 'automation_id', 'automations', $workspaceId);
        $this->deleteWhereIn('automation_runs', 'automation_id', 'automations', 'id', 'workspace_id', null, $workspaceId);

        $this->deleteWhereIn('campaign_recipients', 'campaign_id', 'campaigns', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('campaign_steps', 'campaign_id', 'campaigns', 'id', 'workspace_id', null, $workspaceId);

        $this->deleteWhereIn('ai_kb_chunks', 'document_id', 'ai_kb_documents', 'id', 'kb_id', 'ai_knowledge_bases', $workspaceId);
        $this->deleteWhereIn('ai_kb_chunks', 'kb_id', 'ai_knowledge_bases', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('ai_kb_documents', 'kb_id', 'ai_knowledge_bases', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('ai_runs', 'chatbot_id', 'ai_chatbots', 'id', 'workspace_id', null, $workspaceId);

        $this->deleteWhereIn('widget_push_subscriptions', 'chat_widget_id', 'chat_widgets', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('internal_notes', 'conversation_id', 'conversations', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('inbox_assignments', 'conversation_id', 'conversations', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('inbox_notes', 'conversation_id', 'conversations', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('inbox_label_conversation', 'conversation_id', 'conversations', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('inbox_label_conversation', 'label_id', 'inbox_labels', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('messages', 'conversation_id', 'conversations', 'id', 'workspace_id', null, $workspaceId);

        $this->deleteWhereIn('contact_tag_pivot', 'contact_id', 'contacts', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('contact_tag_pivot', 'tag_id', 'contact_tags', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('segment_contact', 'segment_id', 'segments', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('segment_contact', 'contact_id', 'contacts', 'id', 'workspace_id', null, $workspaceId);

        $this->deleteWhereIn('social_media_post_accounts', 'post_id', 'social_media_posts', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('social_media_post_accounts', 'social_account_id', 'social_media_accounts', 'id', 'workspace_id', null, $workspaceId);

        $this->deleteWhereIn('whatsapp_phone_numbers', 'waba_id_fk', 'whatsapp_business_accounts', 'id', 'workspace_id', null, $workspaceId);
        $this->deleteWhereIn('whatsapp_template_submissions', 'template_id', 'whatsapp_templates', 'id', 'workspace_id', null, $workspaceId);
    }

    /**
     * Delete all direct workspace records, including future feature tables that
     * correctly declare a workspace_id column. Users are reassigned above.
     */
    private function deleteWorkspaceScopedRecords(int $workspaceId): void
    {
        foreach (Schema::getTables() as $definition) {
            $table = $definition['name'] ?? null;
            if (! is_string($table) || in_array($table, ['users', 'workspaces'], true)) {
                continue;
            }
            if (Schema::hasColumn($table, 'workspace_id')) {
                DB::table($table)->where('workspace_id', $workspaceId)->delete();
            }
        }
    }

    /**
     * Delete rows whose foreign key points to a workspace-scoped parent. For a
     * two-hop relation, the intermediate table is filtered through its parent.
     */
    private function deleteWhereIn(
        string $table,
        string $foreignKey,
        string $parentTable,
        string $parentKey,
        string $scopeColumn,
        ?string $scopeParentTable,
        int $workspaceId,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasTable($parentTable)) {
            return;
        }

        $parents = DB::table($parentTable)->select($parentKey);
        if ($scopeParentTable === null) {
            $parents->where($scopeColumn, $workspaceId);
        } elseif (Schema::hasTable($scopeParentTable)) {
            $parents->whereIn(
                $scopeColumn,
                DB::table($scopeParentTable)->select('id')->where('workspace_id', $workspaceId),
            );
        } else {
            return;
        }

        DB::table($table)->whereIn($foreignKey, $parents)->delete();
    }
}
