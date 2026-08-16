<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Broadcasting\Jobs\LaunchCampaignJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\SmsDispatchControl;
use Illuminate\Support\Facades\DB;

class SmsCampaignCapacityService
{
    private const ACTIVE_STATUSES = [
        'preparing', 'sending', 'retrying',
    ];

    public function admit(Campaign $campaign, string $providerKey, int $recipientCount): bool
    {
        $threshold = max(1, (int) config('broadcasting.sms.large_campaign_threshold', 10000));
        $isLarge = $campaign->audience_type === 'csv' || $recipientCount >= $threshold;
        $controlKey = 'provider:'.$providerKey;

        return DB::transaction(function () use ($campaign, $providerKey, $recipientCount, $isLarge, $controlKey) {
            $now = now();
            SmsDispatchControl::insertOrIgnore([
                'key' => $controlKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            /** @var SmsDispatchControl $control */
            $control = SmsDispatchControl::whereKey($controlKey)->lockForUpdate()->firstOrFail();

            // Clear a stale holder whose campaign no longer exists or is terminal.
            if ($control->active_campaign_id) {
                $holder = Campaign::find($control->active_campaign_id);
                if (! $holder || ! in_array($holder->status, self::ACTIVE_STATUSES, true)) {
                    $control->active_campaign_id = null;
                }
            }

            $otherActiveExists = Campaign::query()
                ->where('provider_key', $providerKey)
                ->where('id', '!=', $campaign->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->exists();

            $blocked = ($control->active_campaign_id && $control->active_campaign_id !== $campaign->id)
                || ($isLarge && $otherActiveExists);

            $campaign->update([
                'provider_key' => $providerKey,
                'estimated_recipients' => $recipientCount,
                'is_large' => $isLarge,
                // Mark admitted work active inside the same transaction. If a
                // second launcher arrives before the preparation job starts,
                // it must still see this campaign as occupying capacity.
                'status' => $blocked ? 'waiting_capacity' : 'preparing',
                'pause_reason' => $blocked
                    ? 'Waiting for the active SMS campaign using this provider account to finish.'
                    : null,
            ]);

            if ($blocked) {
                $control->save();

                return false;
            }

            if ($isLarge) {
                $control->active_campaign_id = $campaign->id;
            }
            $control->heartbeat_at = $now;
            $control->save();

            return true;
        }, 5);
    }

    public function release(Campaign $campaign): void
    {
        if (! $campaign->provider_key) {
            return;
        }

        $controlKey = 'provider:'.$campaign->provider_key;
        DB::transaction(function () use ($campaign, $controlKey) {
            $control = SmsDispatchControl::whereKey($controlKey)->lockForUpdate()->first();
            if ($control?->active_campaign_id === $campaign->id) {
                $control->update([
                    'active_campaign_id' => null,
                    'systemic_failure_streak' => 0,
                    'heartbeat_at' => now(),
                ]);
            }
        }, 5);

        $this->wakeNext($campaign->provider_key);
    }

    public function wakeNext(string $providerKey): void
    {
        $next = Campaign::where('provider_key', $providerKey)
            ->where('status', 'waiting_capacity')
            ->orderBy('schedule_at')
            ->orderBy('id')
            ->first();

        if ($next) {
            LaunchCampaignJob::dispatch($next->id)->onQueue('broadcast');
        }
    }

    public function recordHealthySend(string $providerKey): void
    {
        SmsDispatchControl::whereKey('provider:'.$providerKey)->update([
            'systemic_failure_streak' => 0,
            'heartbeat_at' => now(),
        ]);
    }

    public function recordSystemicFailure(Campaign $campaign): bool
    {
        if (! $campaign->provider_key) {
            return false;
        }

        $threshold = max(1, (int) config('broadcasting.sms.systemic_failure_pause_threshold', 3));
        $key = 'provider:'.$campaign->provider_key;

        return DB::transaction(function () use ($key, $threshold) {
            $control = SmsDispatchControl::whereKey($key)->lockForUpdate()->first();
            if (! $control) {
                return false;
            }
            $control->systemic_failure_streak++;
            $control->heartbeat_at = now();
            $control->save();

            return $control->systemic_failure_streak >= $threshold;
        }, 5);
    }
}
