<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignStep;

class CampaignStepService
{
    public function sync(Campaign $campaign, ?array $steps): void
    {
        if ($campaign->channel !== 'sms') {
            $campaign->steps()->delete();

            return;
        }
        if (! in_array($campaign->status, ['draft', 'queued', 'preparing', 'paused', 'safety_paused'], true)) {
            return;
        }
        // Once recipients have been assigned, changing step boundaries would
        // orphan or duplicate delivery work. Content may still be edited while
        // paused, but the prepared delivery plan remains immutable.
        if ($campaign->recipients()->exists()) {
            return;
        }

        $normalised = $this->normalise($steps);
        $keep = [];
        foreach ($normalised as $position => $step) {
            $model = CampaignStep::updateOrCreate(
                ['campaign_id' => $campaign->id, 'position' => $position + 1],
                array_merge($step, ['status' => 'pending']),
            );
            $keep[] = $model->id;
        }

        $campaign->steps()->whereNotIn('id', $keep)->delete();
    }

    public function ensure(Campaign $campaign): void
    {
        if (! $campaign->steps()->exists()) {
            $this->sync($campaign, null);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function normalise(?array $steps): array
    {
        if (empty($steps)) {
            return [
                [
                    'name' => 'Safety check',
                    'recipient_limit' => 100,
                    'delay_after_previous_seconds' => 0,
                    'rate_per_second' => 5,
                ],
                [
                    'name' => 'Remaining contacts',
                    'recipient_limit' => null,
                    'delay_after_previous_seconds' => 600,
                    'rate_per_second' => 5,
                ],
            ];
        }

        $maximum = max(1, (int) config('broadcasting.sms.provider_rate_per_second', 5));
        $out = [];
        foreach (array_slice($steps, 0, 10) as $index => $step) {
            $out[] = [
                'name' => trim((string) ($step['name'] ?? 'Step '.($index + 1))) ?: 'Step '.($index + 1),
                'recipient_limit' => filled($step['recipient_limit'] ?? null)
                    ? max(1, (int) $step['recipient_limit'])
                    : null,
                'delay_after_previous_seconds' => min(86400, max(0, (int) ($step['delay_after_previous_seconds'] ?? 0))),
                'rate_per_second' => min($maximum, max(1, (int) ($step['rate_per_second'] ?? 5))),
            ];
        }

        // The final step always absorbs the remaining audience.
        $out[array_key_last($out)]['recipient_limit'] = null;

        return $out;
    }

    public function forOrdinal(Campaign $campaign, int $ordinal): CampaignStep
    {
        $steps = $campaign->relationLoaded('steps') ? $campaign->steps : $campaign->steps()->get();
        $offset = 0;
        foreach ($steps as $step) {
            if ($step->recipient_limit === null || $ordinal <= $offset + $step->recipient_limit) {
                return $step;
            }
            $offset += $step->recipient_limit;
        }

        return $steps->last();
    }
}
