<?php

namespace App\Modules\Automation\Services;

use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;

/**
 * Decides whether a failed run can be retried, and whether that is safe.
 *
 * The engine claims each step before running it and refuses to run a claimed
 * step again, because after a crash it cannot know whether the message went
 * out. A retry therefore has to answer the same question the claim protects:
 * could the customer already have received this step?
 *
 *  - A step that finished and reported an error was rejected outright (an
 *    invalid template, a closed messaging window). Nothing was delivered, so
 *    it is safe to run again.
 *  - A step with no recorded outcome, or one whose error says delivery needs
 *    review, may have reached the customer. Running it again could send the
 *    same message twice, so it needs a person to check the chat first.
 */
class AutomationRetryPolicy
{
    /**
     * @return array{retryable: bool, needs_confirmation: bool, reason: ?string}
     */
    public function assess(AutomationRun $run): array
    {
        if ($run->status !== 'failed') {
            return $this->refuse('Only failed runs can be retried.');
        }
        if (! $run->automation?->isActive()) {
            return $this->refuse('Turn this automation on before retrying its runs.');
        }
        if (! $run->current_node_id) {
            // Failed before reaching any step — a setup problem such as a
            // missing trigger, which running again would only repeat.
            return $this->refuse('This run failed before its first step. Fix the automation instead.');
        }

        $outcome = AutomationRunLog::where('run_id', $run->id)
            ->where('node_id', $run->current_node_id)
            ->latest('id')
            ->first();

        $definite = $outcome
            && $outcome->result === 'error'
            && ! preg_match('/review|already attempted/i', (string) ($outcome->message ?? '').' '.(string) $run->error);

        if ($definite) {
            return ['retryable' => true, 'needs_confirmation' => false, 'reason' => null];
        }

        return [
            'retryable' => true,
            'needs_confirmation' => true,
            'reason' => 'This step may already have reached the customer. Check the chat first — retrying could send it twice.',
        ];
    }

    /** @return array{retryable: bool, needs_confirmation: bool, reason: string} */
    private function refuse(string $reason): array
    {
        return ['retryable' => false, 'needs_confirmation' => false, 'reason' => $reason];
    }
}
