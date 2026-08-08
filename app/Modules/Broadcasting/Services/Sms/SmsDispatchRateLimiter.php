<?php

namespace App\Modules\Broadcasting\Services\Sms;

use App\Modules\Broadcasting\Models\SmsDispatchControl;
use Illuminate\Support\Facades\DB;

/**
 * Reserves start times atomically. Workers may run concurrently, but provider
 * requests remain globally rate-limited even when many queue workers send in
 * parallel. The initial safety step still runs at 5 TPS; bulk steps may use
 * the verified 180 TPS ceiling.
 */
class SmsDispatchRateLimiter
{
    public function reserve(string $providerKey, int $campaignRate): SmsRateReservation
    {
        $providerRate = min(
            max(1, $campaignRate),
            max(1, (int) config('broadcasting.sms.provider_rate_per_second', 180)),
        );
        $platformRate = max(1, (int) config('broadcasting.sms.platform_rate_per_second', 180));

        return $this->reserveMany(
            [
                'platform' => $platformRate,
                'provider:'.$providerKey => $providerRate,
            ],
            max(250_000, (int) config('broadcasting.sms.max_inline_rate_wait_microseconds', 5_000_000)),
        );
    }

    /**
     * @param  array<string, int>  $limits  key => starts per second
     *                                      Reserve only when a slot is close enough to wait for inside the queue
     *                                      worker. Far-future jobs are deferred without consuming a phantom slot,
     *                                      protecting worker timeouts when many campaigns share one provider.
     */
    public function reserveMany(array $limits, int $maxWaitMicroseconds = 5_000_000): SmsRateReservation
    {
        ksort($limits);
        $nowFloat = microtime(true);

        $result = DB::transaction(function () use ($limits, $nowFloat, $maxWaitMicroseconds): array {
            $now = now();
            foreach (array_keys($limits) as $key) {
                SmsDispatchControl::insertOrIgnore([
                    'key' => $key,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $controls = [];
            $slot = $nowFloat;
            foreach ($limits as $key => $rate) {
                /** @var SmsDispatchControl $control */
                $control = SmsDispatchControl::whereKey($key)->lockForUpdate()->firstOrFail();
                $controls[$key] = $control;
                if ($control->next_slot_at) {
                    $slot = max($slot, (float) $control->next_slot_at->format('U.u'));
                }
            }

            $waitMicroseconds = max(0, (int) round(($slot - $nowFloat) * 1_000_000));
            if ($waitMicroseconds > $maxWaitMicroseconds) {
                return [false, $waitMicroseconds];
            }

            foreach ($limits as $key => $rate) {
                $intervalMicros = (int) ceil(1_000_000 / max(1, $rate));
                $nextFloat = $slot + ($intervalMicros / 1_000_000);
                $next = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $nextFloat));

                $controls[$key]->update([
                    'next_slot_at' => $next?->format('Y-m-d H:i:s.u'),
                    'heartbeat_at' => $now,
                ]);
            }

            return [true, $waitMicroseconds];
        }, 5);

        return new SmsRateReservation(
            (bool) $result[0],
            max(0, (int) $result[1] - (int) round((microtime(true) - $nowFloat) * 1_000_000)),
        );
    }
}
