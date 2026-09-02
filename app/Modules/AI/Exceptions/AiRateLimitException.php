<?php

namespace App\Modules\AI\Exceptions;

use RuntimeException;

class AiRateLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Too many AI requests are running. Wait a moment and try again.');
    }
}
