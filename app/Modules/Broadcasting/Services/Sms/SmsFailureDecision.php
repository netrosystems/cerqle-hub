<?php

namespace App\Modules\Broadcasting\Services\Sms;

final class SmsFailureDecision
{
    public function __construct(
        public readonly string $class,
        public readonly bool $retryable,
        public readonly bool $systemic,
        public readonly bool $unknownOutcome = false,
    ) {}
}
