<?php

namespace App\Modules\Broadcasting\Services\Sms;

class SmsSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $messageId,
        public readonly string $error = '',
        public readonly ?int $httpStatus = null,
        public readonly ?bool $retryable = null,
        public readonly bool $systemic = false,
        public readonly bool $unknownOutcome = false,
        public readonly ?int $retryAfterSeconds = null,
    ) {}
}
