<?php

namespace App\Modules\Automation\Jobs;

use App\Events\AutomationFailed;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Services\ClientAccessService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ExecuteAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $runId, public readonly ?int $expectedWake = null) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('automation-run:'.$this->runId))->releaseAfter(5)->expireAfter(150)];
    }

    public function handle(AutomationEngine $engine): void
    {
        $access = app(ClientAccessService::class);
        $run = AutomationRun::with('automation')->find($this->runId);
        if (! $run || ! $run->automation || in_array($run->status, ['completed', 'cancelled', 'failed'], true)) {
            return;
        }
        if ($this->expectedWake !== null && $run->wake_at?->timestamp !== $this->expectedWake) {
            return;
        }

        if (! $run->automation->isActive()) {
            $run->update(['status' => 'cancelled', 'error' => 'Workflow is paused.', 'completed_at' => now()]);

            return;
        }
        if ($run->wake_at?->isFuture()) {
            return;
        }
        if (data_get($run->context, '_awaiting_reply')) {
            $run->update(['status' => 'cancelled', 'error' => 'Reply timeout expired.', 'completed_at' => now()]);

            return;
        }

        if (! $access->allowsWorkspaceWrite($run->automation->workspace_id)) {
            $run->update([
                'status' => $run->conversation_id ? 'cancelled' : 'waiting',
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
