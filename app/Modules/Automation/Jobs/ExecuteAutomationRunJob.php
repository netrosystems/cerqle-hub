<?php

namespace App\Modules\Automation\Jobs;

use App\Events\AutomationFailed;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Services\ClientAccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(AutomationEngine $engine): void
    {
        $access = app(ClientAccessService::class);
        $run = AutomationRun::with('automation')->find($this->runId);
        if (! $run || in_array($run->status, ['cancelled', 'failed'], true)) {
            return;
        }

        if (! $access->allowsWorkspaceWrite($run->automation->workspace_id)) {
            $run->update([
                'status' => 'waiting',
                'error' => 'Automation paused because the subscription is inactive.',
            ]);

            return;
        }

        try {
            $engine->executeRun($run);
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            AutomationFailed::dispatch($run, $e->getMessage());
            throw $e;
        }
    }
}
