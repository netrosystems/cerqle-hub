<?php

namespace App\Modules\AI\Exceptions;

use RuntimeException;

class AiCreditsExhaustedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cerqle AI credits are exhausted. Add your API provider or upgrade your plan.');
    }
}
