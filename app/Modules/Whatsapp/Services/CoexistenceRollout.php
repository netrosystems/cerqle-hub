<?php

namespace App\Modules\Whatsapp\Services;

class CoexistenceRollout
{
    public static function enabledFor(int $workspaceId): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) config('whatsapp.coexistence_workspaces', ''))));

        return (bool) config('whatsapp.coexistence_enabled')
            && ($allowed === [] || in_array((string) $workspaceId, $allowed, true));
    }
}
