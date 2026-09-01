<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Permanently removes a client identity and operational data while anonymizing finance/audit history. */
class ClientDeletionService
{
    public function __construct(
        private readonly WorkspaceDeletionService $workspaces,
        private readonly StorageManager $storage,
    ) {}

    /** @return array{client_id:int,users:int,workspaces:int} */
    public function delete(Client $client): array
    {
        $files = [];

        $result = DB::transaction(function () use ($client, &$files): array {
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            $userIds = $client->users()->pluck('id');
            $workspaceIds = Workspace::query()->where('client_id', $client->id)->pluck('id');
            $mediaIds = collect();

            if ($client->logo_path) {
                $files[] = [$client->logo_disk ?: 'public', $client->logo_path];
            }
            foreach ($client->users()->whereNotNull('avatar')->pluck('avatar') as $avatar) {
                if (! str_starts_with($avatar, 'http://') && ! str_starts_with($avatar, 'https://')) {
                    $files[] = [$this->storage->diskName(), $avatar];
                }
            }

            if (Schema::hasTable('media_social_post') && Schema::hasTable('social_media_posts')) {
                $mediaIds = DB::table('media_social_post')
                    ->whereIn('social_post_id', DB::table('social_media_posts')->select('id')->whereIn('workspace_id', $workspaceIds))
                    ->pluck('media_id');
                if ($mediaIds->isNotEmpty()) {
                    foreach (DB::table('media')->whereIn('id', $mediaIds)->get(['disk', 'path']) as $media) {
                        $files[] = [$media->disk ?: 'public', $media->path];
                    }
                }
            }

            if ($userIds->isNotEmpty()) {
                foreach (DB::table('payment_transactions')->whereIn('user_id', $userIds)->whereNotNull('invoice_path')->pluck('invoice_path') as $invoicePath) {
                    $files[] = ['local', $invoicePath];
                }
                DB::table('payment_transactions')->whereIn('user_id', $userIds)->update([
                    'user_id' => null,
                    'subscription_id' => null,
                    'payload' => null,
                    'invoice_path' => null,
                    'refund_reason' => null,
                ]);
                DB::table('audit_logs')->whereIn('user_id', $userIds)->update($this->anonymousAuditValues());
                if (Schema::hasTable('support_tickets')) {
                    DB::table('support_tickets')->whereIn('user_id', $userIds)->delete();
                }
            }

            DB::table('audit_logs')->where('client_id', $client->id)->update($this->anonymousAuditValues());
            if (Schema::hasTable('invitations')) {
                DB::table('invitations')->where('client_id', $client->id)->delete();
            }

            foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
                $this->workspaces->purgeForClient($workspace);
            }

            if ($mediaIds->isNotEmpty()) {
                DB::table('media')->whereIn('id', $mediaIds)
                    ->whereNotExists(fn ($query) => $query
                        ->selectRaw('1')
                        ->from('media_social_post')
                        ->whereColumn('media_social_post.media_id', 'media.id'))
                    ->delete();
            }

            if ($userIds->isNotEmpty()) {
                DB::table('password_reset_tokens')->whereIn('email', $client->users()->pluck('email'))->delete();
                $client->users()->delete();
            }

            $result = [
                'client_id' => (int) $client->id,
                'users' => $userIds->count(),
                'workspaces' => $workspaceIds->count(),
            ];
            $client->delete();

            return $result;
        });

        foreach (array_unique($files, SORT_REGULAR) as [$disk, $path]) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
                // Database deletion is authoritative; storage cleanup can be retried by maintenance.
            }
        }

        return $result;
    }

    private function anonymousAuditValues(): array
    {
        return [
            'user_id' => null,
            'client_id' => null,
            'auditable_type' => null,
            'auditable_id' => null,
            'old_values' => null,
            'new_values' => null,
            'meta' => null,
            'ip' => null,
            'user_agent' => null,
            'url' => null,
        ];
    }
}
