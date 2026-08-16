<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Broadcasting\Services\CampaignStepService;
use App\Modules\Broadcasting\Services\Sms\SmsDispatchRateLimiter;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Broadcasting\Services\Sms\SmsFailureClassifier;
use App\Modules\Broadcasting\Services\Sms\SmsSendResult;
use App\Modules\Broadcasting\Services\SmsCampaignCapacityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSmsCampaignMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Provider retries are recorded in campaign_recipients and scheduled by the
    // pump. Laravel retries are reserved for an unexpected worker-level crash.
    public int $tries = 3;

    public array $backoff = [5, 30];

    public int $timeout = 60;

    public function __construct(public readonly int $recipientId) {}

    public function handle(
        CampaignPersonalizer $personalizer,
        SmsDispatchRateLimiter $limiter,
        SmsFailureClassifier $classifier,
        SmsCampaignCapacityService $capacity,
        CampaignStepService $steps,
    ): void {
        $recipient = CampaignRecipient::with(['campaign', 'contact', 'step'])->find($this->recipientId);
        if (! $recipient || ! $recipient->campaign || ! $recipient->contact) {
            return;
        }

        $campaign = $recipient->campaign;
        if (! in_array($campaign->status, ['sending', 'retrying'], true)) {
            $this->returnToQueue($recipient, 'Campaign is not currently sending.');

            return;
        }

        if (! $recipient->contact->opt_in_sms || ! filled($recipient->contact->phone_e164)) {
            $recipient->update([
                'status' => 'failed',
                'failure_class' => 'recipient',
                'failed_reason' => 'Contact is not opted in for SMS or has no valid phone number.',
                'opted_out_at' => ! $recipient->contact->opt_in_sms ? now() : null,
                'claimed_at' => null,
            ]);

            return;
        }

        $resolved = SmsDriverManager::resolveForWorkspace($campaign->workspace_id, $campaign->sms_provider);
        if (! hash_equals((string) $campaign->provider_key, $resolved->providerKey)) {
            $campaign->update([
                'status' => 'safety_paused',
                'pause_reason' => 'SMS provider credentials changed during the campaign. Review the gateway and resume safely.',
            ]);
            $capacity->release($campaign->fresh());
            $this->returnToQueue($recipient, 'Provider credentials changed during delivery.', 60);

            return;
        }

        // The step's stored rate was already clamped to the position-aware
        // max during CampaignStepService::normalise() (provider rate for
        // step 1, platform rate for everything after). Re-clamp at runtime
        // so a step rate that slipped past the normaliser — or a cap change
        // between save and dispatch — cannot exceed the live policy.
        $position = (int) ($recipient->step?->position ?? 1);
        $maximum = $steps->maxRateForStep($position, $resolved->throughputTps);
        $rate = min($maximum, max(1, (int) ($recipient->step?->rate_per_second ?? $maximum)));
        $reservation = $limiter->reserve($resolved->providerKey, $rate, $resolved->throughputTps);
        if (! $reservation->reserved) {
            $this->deferForRateLimit($recipient, $reservation->waitMicroseconds);

            return;
        }
        if ($reservation->waitMicroseconds > 0) {
            usleep($reservation->waitMicroseconds);
        }

        // A campaign can be paused while this job waits for its reserved slot.
        $campaign->refresh();
        if (! in_array($campaign->status, ['sending', 'retrying'], true)) {
            $this->returnToQueue($recipient, 'Campaign was paused before the provider request.');

            return;
        }

        $body = $personalizer->renderText(
            (string) ($campaign->payload_json['body'] ?? ''),
            $recipient->contact,
        );
        if (trim($body) === '') {
            $recipient->update([
                'status' => 'failed',
                'failure_class' => 'content',
                'failed_reason' => 'SMS body is empty after personalization.',
                'claimed_at' => null,
            ]);

            return;
        }

        $recipient->update([
            'status' => 'sending',
            'attempts' => $recipient->attempts + 1,
            'next_attempt_at' => null,
        ]);
        $recipient->refresh();

        try {
            $result = $resolved->driver->send($recipient->contact->phone_e164, $body);
        } catch (\Throwable $exception) {
            $result = new SmsSendResult(
                false,
                '',
                'SMS worker error: '.$exception->getMessage(),
                null,
                true,
            );
        }

        if ($result->success) {
            $confirmedAt = now();
            $recipient->update([
                // Most SMS gateways acknowledge accepted delivery immediately,
                // but do not expose a read receipt. Treat that acknowledgement
                // as Delivered rather than leaving the UI permanently at Sent.
                'status' => 'delivered',
                'provider_message_id' => $result->messageId,
                'sent_at' => $confirmedAt,
                'delivered_at' => $confirmedAt,
                'failed_reason' => null,
                'failure_class' => null,
                'claimed_at' => null,
                'next_attempt_at' => null,
            ]);
            $capacity->recordHealthySend($resolved->providerKey);
            UsageMeter::track($campaign->workspace_id, 'messages_sms');

            Log::channel('json')->info('campaign.sms.sent', [
                'workspace_id' => $campaign->workspace_id,
                'campaign_id' => $campaign->id,
                'recipient_id' => $recipient->id,
                'attempt' => $recipient->attempts,
                'message_id' => $result->messageId,
            ]);

            return;
        }

        $decision = $classifier->classify($result);
        $retryDelays = array_values((array) config('broadcasting.sms.retry_delays', [3, 15, 60, 300, 900]));
        // 30 seconds is the absolute floor between retries. Tight loops (the
        // historical default of 3 s) cause the pump to recycle the same
        // failing recipients instead of draining the rest of the audience.
        $retryFloor = max(30, (int) config('broadcasting.sms.minimum_retry_seconds', 30));
        $canRetry = $decision->retryable && $recipient->attempts <= count($retryDelays);
        $retryDelayIndex = min(max(0, $recipient->attempts - 1), max(0, count($retryDelays) - 1));
        $retryDelay = (int) ($retryDelays[$retryDelayIndex] ?? 900);
        $delay = $canRetry
            ? max($retryFloor, (int) ($result->retryAfterSeconds ?? 0), $retryDelay)
            : null;

        // Systemic failures pause the campaign after a short streak, but each
        // recipient still obeys the finite retry budget. This prevents an
        // invalid credential from creating an immortal retry loop.
        $shouldRetry = $canRetry || ($decision->systemic && $recipient->attempts <= count($retryDelays));
        $systemicDelay = max($retryFloor, $retryDelay);

        $recipient->update([
            'status' => $shouldRetry ? 'retrying' : 'failed',
            'next_attempt_at' => $shouldRetry ? now()->addSeconds($delay ?: $systemicDelay) : null,
            'claimed_at' => null,
            'failed_reason' => substr($result->error ?: 'Provider rejected the SMS.', 0, 512),
            'failure_class' => $decision->class,
        ]);

        if ($decision->systemic && $capacity->recordSystemicFailure($campaign)) {
            $campaign->update([
                'status' => 'safety_paused',
                'pause_reason' => 'Sending paused automatically after repeated provider configuration or authentication failures.',
            ]);
            $capacity->release($campaign->fresh());
        }

        Log::channel('json')->warning('campaign.sms.failed', [
            'workspace_id' => $campaign->workspace_id,
            'campaign_id' => $campaign->id,
            'recipient_id' => $recipient->id,
            'attempt' => $recipient->attempts,
            'failure_class' => $decision->class,
            'retry_at' => optional($recipient->fresh()->next_attempt_at)->toIso8601String(),
            'unknown_outcome' => $decision->unknownOutcome,
            'error' => $result->error,
        ]);
    }

    private function returnToQueue(CampaignRecipient $recipient, string $reason, int $delay = 0): void
    {
        $recipient->update([
            'status' => $delay > 0 ? 'retrying' : 'queued',
            'next_attempt_at' => $delay > 0 ? now()->addSeconds($delay) : null,
            'claimed_at' => null,
            'failed_reason' => $reason,
        ]);
    }

    private function deferForRateLimit(CampaignRecipient $recipient, int $waitMicroseconds): void
    {
        // Floor at 1 second so we never spin the same recipient inside a
        // smaller window. The provider's far-future release is already
        // reserved when the worker picked the slot.
        $seconds = max(1, (int) ceil($waitMicroseconds / 1_000_000));
        $recipient->update([
            'status' => 'retrying',
            'next_attempt_at' => now()->addSeconds($seconds),
            'claimed_at' => null,
            'failure_class' => 'rate_limit_wait',
            'failed_reason' => 'Waiting for shared SMS provider capacity.',
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $recipient = CampaignRecipient::find($this->recipientId);
        if (! $recipient || in_array($recipient->status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $recipient->update([
            'status' => 'retrying',
            'next_attempt_at' => now()->addMinute(),
            'claimed_at' => null,
            'failure_class' => 'worker_crash',
            'failed_reason' => substr('Worker stopped unexpectedly: '.$exception->getMessage(), 0, 512),
        ]);
    }
}
