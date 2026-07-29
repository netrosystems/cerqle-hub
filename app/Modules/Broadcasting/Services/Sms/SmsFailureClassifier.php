<?php

namespace App\Modules\Broadcasting\Services\Sms;

class SmsFailureClassifier
{
    public function classify(SmsSendResult $result): SmsFailureDecision
    {
        $message = strtolower($result->error);

        if ($result->systemic || $this->contains($message, [
            'unauthorized', 'not authorized', 'check login', 'authentication',
            'invalid api key', 'invalid credential', 'incorrect password',
        ])) {
            return new SmsFailureDecision('authentication', false, true);
        }

        if ($this->contains($message, [
            'service type is invalid', 'long message mode contains incorrect',
            'sender id is invalid', 'invalid sender', 'invalid ani',
        ])) {
            return new SmsFailureDecision('configuration', false, true);
        }

        if ($this->contains($message, ['no routes', 'no route'])) {
            return new SmsFailureDecision('no_route', false, false);
        }

        if ($this->contains($message, [
            'invalid number', 'invalid destination', 'destination number is too long',
            'invalid dnis', 'opted_out', 'loop detected',
        ])) {
            return new SmsFailureDecision('recipient', false, false);
        }

        if ($result->retryable === true || $result->httpStatus === 429 || ($result->httpStatus ?? 0) >= 500) {
            return new SmsFailureDecision(
                $result->unknownOutcome ? 'unknown_outcome' : 'temporary',
                true,
                false,
                $result->unknownOutcome,
            );
        }

        if ($this->contains($message, [
            'timeout', 'timed out', 'connection failed', 'connection refused',
            'temporarily unavailable', 'too many requests', 'rate limit',
            'bad gateway', 'service unavailable', 'gateway timeout',
        ])) {
            return new SmsFailureDecision('temporary', true, false, str_contains($message, 'timeout'));
        }

        // Unknown provider rejections are not retried automatically: retrying
        // an accepted-but-unacknowledged submission can deliver duplicates.
        return new SmsFailureDecision('provider_rejection', false, false);
    }

    private function contains(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
