<?php

namespace App\Modules\Broadcasting\Services\Sms;

class SmsRateReservation
{
    public function __construct(
        public readonly bool $reserved,
        public readonly int $waitMicroseconds,
    ) {}
}
