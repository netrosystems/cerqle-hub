<?php

namespace App\Modules\AI\Exceptions;

use RuntimeException;

class AiRequestInProgressException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This AI action is already running. Wait for it to finish before retrying.');
    }
}
