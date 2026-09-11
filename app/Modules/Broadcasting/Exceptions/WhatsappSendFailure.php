<?php

namespace App\Modules\Broadcasting\Exceptions;

use RuntimeException;

class WhatsappSendFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureClass,
    ) {
        parent::__construct($message);
    }
}
