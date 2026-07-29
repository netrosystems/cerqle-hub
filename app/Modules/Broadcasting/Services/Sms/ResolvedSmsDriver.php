<?php

namespace App\Modules\Broadcasting\Services\Sms;

final class ResolvedSmsDriver
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerKey,
        public readonly SmsDriverInterface $driver,
    ) {}
}
