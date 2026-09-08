<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Support\Facades\Http;

class WhatsappWebhookFields
{
    public static function coexistenceRequired(): bool
    {
        return (bool) config('whatsapp.coexistence_enabled')
            || WhatsappPhoneNumber::where('connection_mode', 'coexistence')->exists();
    }

    public static function registrationFields(string $appId, string $appToken): string
    {
        $fields = ['messages', 'message_template_status_update', 'phone_number_name_update',
            'phone_number_quality_update', 'account_update'];
        if (self::coexistenceRequired()) {
            $fields = array_merge($fields, ['smb_message_echoes', 'history', 'smb_app_state_sync']);
            // Preserve other subscriptions rather than overwriting an operator's settings.
            $response = Http::withToken($appToken)->timeout(20)
                ->get('https://graph.facebook.com/v25.0/'.$appId.'/subscriptions');
            if (! $response->successful()) {
                throw new \RuntimeException('Cannot read existing webhook fields; no subscription settings were overwritten.');
            }
            foreach ($response->json('data', []) as $subscription) {
                if (($subscription['object'] ?? '') !== 'whatsapp_business_account') {
                    continue;
                }
                foreach ($subscription['fields'] ?? [] as $field) {
                    if (is_string($field['name'] ?? null)) {
                        $fields[] = $field['name'];
                    }
                }
            }
        }

        return implode(',', array_unique($fields));
    }
}
