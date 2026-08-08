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
                    'rate_per_second' => $this->maxRateForStep(1),
                ],
                [
                    'name' => 'Remaining contacts',
                    'recipient_limit' => null,
                    'delay_after_previous_seconds' => 600,
                    'rate_per_second' => $this->maxRateForStep(2),
                ],
            ];
        }

        $out = [];
        foreach (array_slice($steps, 0, 10) as $index => $step) {
            $position = $index + 1;
            $maximum = $this->maxRateForStep($position);
            $out[] = [
                'name' => trim((string) ($step['name'] ?? 'Step '.$position)) ?: 'Step '.$position,
                'recipient_limit' => filled($step['recipient_limit'] ?? null)
                    ? max(1, (int) $step['recipient_limit'])
                    : null,
                'delay_after_previous_seconds' => min(86400, max(0, (int) ($step['delay_after_previous_seconds'] ?? 0))),
                'rate_per_second' => min($maximum, max(1, (int) ($step['rate_per_second'] ?? $maximum))),
            ];
        }

        // The final step always absorbs the remaining audience.
        $out[array_key_last($out)]['recipient_limit'] = null;

        return $out;
    }

    /**
     * Maximum rate per second a step at the given position may use.
     *
     * Position 1 is the "safety check" step: it is deliberately capped at
     * 5 TPS by default so a handful of bad numbers cannot trigger a
     * provider-level rate-limit block before the route is known-good. Every
     * later step can use the verified gateway ceiling (180 TPS by default),
     * shared across all campaigns using the same provider credentials.
     */
    public function maxRateForStep(int $position): int
    {
        if ($position <= 1) {
            return max(1, (int) config('broadcasting.sms.safety_rate_per_second', 5));
        }

        return max(1, min(
            (int) config('broadcasting.sms.provider_rate_per_second', 180),
            (int) config('broadcasting.sms.platform_rate_per_second', 180),
        ));
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
