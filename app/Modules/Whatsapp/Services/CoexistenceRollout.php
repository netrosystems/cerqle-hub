<?php

namespace App\Modules\Whatsapp\Services;

class CoexistenceRollout
{
    public static function enabled(): bool
    {
        return (bool) config('whatsapp.coexistence_enabled');
    }
}
