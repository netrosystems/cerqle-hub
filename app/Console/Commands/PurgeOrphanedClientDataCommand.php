<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceDeletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeOrphanedClientDataCommand extends Command
{
    protected $signature = 'clients:purge-orphans {--execute : Permanently remove the reported orphan records}';

    protected $description = 'Report or purge users and workspaces whose client record no longer exists';

    public function handle(WorkspaceDeletionService $workspaceDeletion): int
    {
        $clientIds = Client::query()->select('id');
        $orphanWorkspaces = Workspace::query()->whereNotNull('client_id')->whereNotIn('client_id', clone $clientIds)->get();
        $orphanUsers = User::query()->whereNotNull('client_id')->whereNotIn('client_id', clone $clientIds)->get();

        $this->table(['Record', 'Count'], [
            ['orphan workspaces', $orphanWorkspaces->count()],
            ['orphan users', $orphanUsers->count()],
        ]);

        if (! $this->option('execute')) {
            $this->info('Dry run only. Re-run with --execute after reviewing the counts.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($workspaceDeletion, $orphanWorkspaces, $orphanUsers): void {
            foreach ($orphanWorkspaces as $workspace) {
                $workspaceDeletion->purgeForClient($workspace);
            }

            $userIds = $orphanUsers->pluck('id');
            if ($userIds->isEmpty()) {
                return;
            }

            DB::table('payment_transactions')->whereIn('user_id', $userIds)->update([
                'user_id' => null,
                'subscription_id' => null,
                'payload' => null,
                'invoice_path' => null,
                'refund_reason' => null,
            ]);
            DB::table('audit_logs')->whereIn('user_id', $userIds)->update([
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
            ]);
            if (Schema::hasTable('support_tickets')) {
                DB::table('support_tickets')->whereIn('user_id', $userIds)->delete();
            }

            User::query()->whereIn('id', $userIds)->delete();
        });

        $this->info('Orphaned client data was permanently purged.');

        return self::SUCCESS;
    }
}
