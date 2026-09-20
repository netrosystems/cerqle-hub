<?php

namespace App\Modules\AI\Console;

use App\Modules\AI\Models\AiAnswerDiagnostic;
use Illuminate\Console\Command;

/**
 * Diagnostics write one row per answered turn, so the table grows with traffic
 * and needs a floor. Knowledge gaps are deliberately not pruned: they are a
 * small, deduplicated list a client is expected to work through.
 */
class PruneAnswerDiagnostics extends Command
{
    protected $signature = 'ai:prune-diagnostics {--days= : Override the configured retention window}';

    protected $description = 'Delete Smart Bot answer diagnostics older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('ai.smart_bot.diagnostics_retention_days', 90));
        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        // Chunked so a large backlog cannot hold a long transaction open.
        do {
            $batch = AiAnswerDiagnostic::where('created_at', '<', $cutoff)->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Deleted {$deleted} diagnostics rows older than {$days} days.");

        return self::SUCCESS;
    }
}
