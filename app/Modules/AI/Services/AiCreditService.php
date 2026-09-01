<?php

namespace App\Modules\AI\Services;

use App\Models\ClientSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Exceptions\AiRateLimitException;
use App\Modules\AI\Models\AiCreditAdjustment;
use App\Modules\AI\Models\AiCreditPeriod;
use App\Modules\AI\Models\AiCreditUsage;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiWorkspaceSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;

class AiCreditService
{
    /** @return array{period: AiCreditPeriod, mode: string, allowance: int} */
    private function context(Workspace $workspace): array
    {
        $owner = $workspace->owner;
        $subscription = null;
        $accountType = 'user';
        $accountId = (int) $workspace->owner_id;

        if ($workspace->client_id) {
            $accountType = 'client';
            $accountId = (int) $workspace->client_id;
            $subscription = $workspace->client?->activeSubscription;
            $subscription ??= ClientSubscription::query()
                ->where('client_id', $workspace->client_id)
                ->where('status', ClientSubscription::STATUS_CANCELLED)
                ->where('ends_at', '>', now())
                ->latest('id')->first();
        }
        $subscription ??= $owner?->effectiveSubscription();
        $subscription ??= $owner?->subscriptions()
            ->where('status', 'cancelled')->where('ends_at', '>', now())->latest('id')->first();

        $allowance = max(0, (int) ($subscription?->plan?->limitValue('ai_credits_per_month') ?? 0));
        $origin = CarbonImmutable::instance($subscription?->starts_at ?? $owner?->created_at ?? now())->startOfSecond();
        $anchor = $origin;
        $now = CarbonImmutable::now();
        $month = 1;
        while ($origin->addMonthsNoOverflow($month)->lte($now)) {
            $anchor = $origin->addMonthsNoOverflow($month);
            $month++;
        }
        $end = $origin->addMonthsNoOverflow($month);
        $subscriptionType = $subscription instanceof ClientSubscription ? 'client' : ($subscription instanceof Subscription ? 'user' : null);

        $period = AiCreditPeriod::firstOrCreate([
            'account_type' => $accountType,
            'account_id' => $accountId,
            'period_start' => $anchor,
        ], [
            'subscription_type' => $subscriptionType,
            'subscription_id' => $subscription?->id,
            'period_end' => $end,
            'allowance' => $allowance,
        ]);

        // Upgrades take effect immediately; a downgrade never removes credits
        // already granted in the current period.
        if ($allowance > $period->allowance) {
            $period->update(['allowance' => $allowance, 'subscription_type' => $subscriptionType, 'subscription_id' => $subscription?->id]);
        }

        $legacyByok = AiProviderConfig::where('workspace_id', $workspace->id)
            ->where('enabled', true)->exists();
        $mode = AiWorkspaceSetting::firstOrCreate(
            ['workspace_id' => $workspace->id],
            ['provider_mode' => $legacyByok ? 'byok' : 'managed'],
        )->provider_mode;

        $isFree = (bool) ($subscription?->plan?->isFree() ?? false);

        return compact('period', 'mode', 'allowance', 'isFree');
    }

    public function usageForWorkspace(int|Workspace $workspace): array
    {
        $workspace = $workspace instanceof Workspace ? $workspace : Workspace::findOrFail($workspace);
        $context = $this->context($workspace);
        $period = $context['period']->fresh();
        $remaining = max(0, $period->allowance - $period->used_credits - $period->reserved_credits);
        $percent = $period->allowance > 0 ? min(100, (int) round((($period->used_credits + $period->reserved_credits) / $period->allowance) * 100)) : 100;

        return [
            'allowance' => $period->allowance,
            'used' => $period->used_credits,
            'reserved' => $period->reserved_credits,
            'remaining' => $remaining,
            'percent_used' => $percent,
            'resets_at' => $period->period_end->toIso8601String(),
            'mode' => $context['mode'],
            'exhausted' => $remaining === 0,
            'warning' => $percent >= 80,
        ];
    }

    public function mode(int $workspaceId): string
    {
        return $this->context(Workspace::findOrFail($workspaceId))['mode'];
    }

    public function setMode(int $workspaceId, string $mode): void
    {
        if (! in_array($mode, ['managed', 'byok', 'auto_fallback'], true)) {
            throw new LogicException('Invalid AI provider mode.');
        }
        AiWorkspaceSetting::updateOrCreate(['workspace_id' => $workspaceId], ['provider_mode' => $mode]);
    }

    /** Reserve credits atomically. Returns an existing ledger row for retries. */
    public function reserve(int $workspaceId, string $featureKey, ?string $idempotencyKey = null, ?User $actor = null): AiCreditUsage
    {
        $rates = config('ai.credits.rates', []);
        if (! array_key_exists($featureKey, $rates)) {
            throw new LogicException("Unknown managed-AI feature key: {$featureKey}");
        }
        $credits = (int) $rates[$featureKey];
        $workspace = Workspace::findOrFail($workspaceId);
        $context = $this->context($workspace);
        $periodId = $context['period']->id;
        $isFree = $context['isFree'];
        $key = $workspaceId.':'.($idempotencyKey ?: (string) Str::uuid());

        if (! app()->runningInConsole() && request()) {
            $identity = $context['period']->account_type.':'.$context['period']->account_id;
            $device = hash('sha256', (string) request()->ip().'|'.(string) request()->userAgent());
            $limit = (int) config($isFree ? 'ai.abuse.free_requests_per_minute' : 'ai.abuse.paid_requests_per_minute');
            foreach (["ai:account:{$identity}", 'ai:user:'.($actor?->id ?? auth()->id()), "ai:device:{$device}"] as $rateKey) {
                if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
                    throw new AiRateLimitException;
                }
                RateLimiter::hit($rateKey, 60);
            }
        }

        return DB::transaction(function () use ($periodId, $workspace, $featureKey, $credits, $key, $actor, $isFree) {
            $existing = AiCreditUsage::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status === 'refunded') {
                    $period = AiCreditPeriod::whereKey($periodId)->lockForUpdate()->firstOrFail();
                    $available = $period->allowance - $period->used_credits - $period->reserved_credits;
                    if ($available < $credits && config('ai.credits.enforced')) {
                        throw new AiCreditsExhaustedException;
                    }
                    $reserved = config('ai.credits.enforced') ? $credits : min($credits, max(0, $available));
                    $period->increment('reserved_credits', $reserved);
                    $existing->update([
                        'reserved_credits' => $reserved,
                        'charged_credits' => 0,
                        'status' => 'reserved',
                        'error_code' => null,
                        'completed_at' => null,
                    ]);
                    $existing->wasRecentlyCreated = true;
                }

                return $existing;
            }

            $period = AiCreditPeriod::whereKey($periodId)->lockForUpdate()->firstOrFail();
            $user = $actor ?? auth()->user() ?? $workspace->owner;
            if ($isFree && ! $user?->hasVerifiedEmail()) {
                throw new LogicException('Verify your email before using included Cerqle AI credits.');
            }

            $concurrency = (int) config($isFree ? 'ai.abuse.free_concurrency' : 'ai.abuse.paid_concurrency');
            if (AiCreditUsage::where('period_id', $period->id)->where('status', 'reserved')->count() >= $concurrency) {
                throw new AiRateLimitException;
            }

            $available = $period->allowance - $period->used_credits - $period->reserved_credits;
            if ($available < $credits && config('ai.credits.enforced')) {
                throw new AiCreditsExhaustedException;
            }

            // Shadow mode still records true demand, but cannot make the
            // accounting counters negative or exceed the configured allowance.
            $reserved = config('ai.credits.enforced') ? $credits : min($credits, max(0, $available));
            $period->increment('reserved_credits', $reserved);

            return AiCreditUsage::create([
                'period_id' => $period->id,
                'workspace_id' => $workspace->id,
                'actor_user_id' => $user?->id,
                'feature_key' => $featureKey,
                'rate_version' => config('ai.credits.rates_version'),
                'idempotency_key' => $key,
                'provider_source' => 'managed',
                'provider' => config('ai.managed.provider'),
                'reserved_credits' => $reserved,
                'status' => 'reserved',
            ]);
        }, 3);
    }

    public function beginByok(int $workspaceId, string $featureKey, ?string $idempotencyKey, string $provider): AiCreditUsage
    {
        if (! array_key_exists($featureKey, config('ai.credits.rates', []))) {
            throw new LogicException("Unknown AI feature key: {$featureKey}");
        }
        $workspace = Workspace::findOrFail($workspaceId);
        $period = $this->context($workspace)['period'];
        $key = $workspaceId.':'.($idempotencyKey ?: (string) Str::uuid());

        return AiCreditUsage::firstOrCreate(['idempotency_key' => $key], [
            'period_id' => $period->id,
            'workspace_id' => $workspaceId,
            'actor_user_id' => auth()->id() ?: $workspace->owner_id,
            'feature_key' => $featureKey,
            'rate_version' => config('ai.credits.rates_version'),
            'provider_source' => 'byok',
            'provider' => $provider,
            'status' => 'reserved',
        ]);
    }

    public function complete(AiCreditUsage $usage, array $result, int $promptTokens, int $completionTokens, string $model, bool $internalRetry = false): void
    {
        DB::transaction(function () use ($usage, $result, $promptTokens, $completionTokens, $model, $internalRetry): void {
            $usage = AiCreditUsage::whereKey($usage->id)->lockForUpdate()->firstOrFail();
            if ($usage->status === 'succeeded') {
                if ($internalRetry) {
                    $usage->update([
                        'prompt_tokens' => $usage->prompt_tokens + $promptTokens,
                        'completion_tokens' => $usage->completion_tokens + $completionTokens,
                        'cost_microusd' => $usage->cost_microusd + ($usage->provider_source === 'managed' ? $this->estimatedCost($model, $promptTokens, $completionTokens) : 0),
                        'model' => $model,
                        'result_payload' => $result,
                    ]);
                }

                return;
            }
            $period = AiCreditPeriod::whereKey($usage->period_id)->lockForUpdate()->firstOrFail();
            $period->reserved_credits = max(0, $period->reserved_credits - $usage->reserved_credits);
            $period->used_credits += $usage->reserved_credits;
            $period->save();
            $usage->update([
                'charged_credits' => $usage->reserved_credits,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'model' => $model,
                'cost_microusd' => $usage->provider_source === 'managed' ? $this->estimatedCost($model, $promptTokens, $completionTokens) : 0,
                'result_payload' => $result,
                'status' => 'succeeded',
                'completed_at' => now(),
            ]);
        }, 3);
    }

    public function refund(AiCreditUsage $usage, string $errorCode = 'provider_failure'): void
    {
        DB::transaction(function () use ($usage, $errorCode): void {
            $usage = AiCreditUsage::whereKey($usage->id)->lockForUpdate()->firstOrFail();
            if ($usage->status !== 'reserved') {
                return;
            }
            $period = AiCreditPeriod::whereKey($usage->period_id)->lockForUpdate()->firstOrFail();
            $period->update(['reserved_credits' => max(0, $period->reserved_credits - $usage->reserved_credits)]);
            $usage->update(['status' => 'refunded', 'error_code' => $errorCode, 'completed_at' => now()]);
        }, 3);
    }

    public function refundCompleted(int $usageId, string $errorCode): void
    {
        DB::transaction(function () use ($usageId, $errorCode): void {
            $usage = AiCreditUsage::whereKey($usageId)->lockForUpdate()->first();
            if (! $usage || $usage->status !== 'succeeded' || $usage->charged_credits === 0) {
                return;
            }
            $period = AiCreditPeriod::whereKey($usage->period_id)->lockForUpdate()->firstOrFail();
            $period->update(['used_credits' => max(0, $period->used_credits - $usage->charged_credits)]);
            $usage->update([
                'charged_credits' => 0,
                'status' => 'refunded',
                'error_code' => $errorCode,
                'completed_at' => now(),
            ]);
        }, 3);
    }

    public function reconcileStale(): int
    {
        $count = 0;
        AiCreditUsage::where('status', 'reserved')
            ->where('created_at', '<=', now()->subMinutes((int) config('ai.credits.reservation_ttl_minutes', 10)))
            ->each(function (AiCreditUsage $usage) use (&$count): void {
                $this->refund($usage, 'reservation_timeout');
                $count++;
            });

        return $count;
    }

    public function adjust(AiCreditPeriod $period, int $credits, string $reason, ?int $adminUserId): void
    {
        if ($credits === 0) {
            throw new LogicException('Adjustment must grant or revoke at least one credit.');
        }
        DB::transaction(function () use ($period, $credits, $reason, $adminUserId): void {
            $period = AiCreditPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
            $nextAllowance = $period->allowance + $credits;
            if ($nextAllowance < ($period->used_credits + $period->reserved_credits)) {
                throw new LogicException('Credits cannot be revoked below current used and reserved usage.');
            }
            $period->update(['allowance' => $nextAllowance]);
            AiCreditAdjustment::create([
                'period_id' => $period->id,
                'admin_user_id' => $adminUserId,
                'credits' => $credits,
                'reason' => $reason,
            ]);
        }, 3);
    }

    private function estimatedCost(string $model, int $input, int $output): int
    {
        $pricing = config("ai.managed.pricing_microusd_per_million.{$model}", ['input' => 0, 'output' => 0]);
        $inputRate = (int) $pricing['input'];
        $outputRate = (int) $pricing['output'];

        return (int) ceil(($input * $inputRate + $output * $outputRate) / 1000000);
    }
}
