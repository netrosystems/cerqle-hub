<?php

namespace App\Services;

use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Support\Facades\Config;

class GoogleSignInConfigurator
{
    /**
     * Apply the Super Admin Google Sign-In client to Socialite.
     *
     * Existing environment credentials remain a deployment-safe fallback until
     * the new integration has been configured. Once credentials are saved in
     * Super Admin, its enabled toggle becomes authoritative.
     */
    public function apply(): bool
    {
        $integration = IntegrationConfig::forProvider('oauth_google_signin');

        if ($integration?->isConfigured()) {
            if (! $integration->enabled) {
                return false;
            }

            $credentials = $integration->credentials ?? [];
            Config::set('services.google.client_id', $credentials['client_id']);
            Config::set('services.google.client_secret', $credentials['client_secret']);
            Config::set('services.google.redirect', route('auth.social.callback', 'google'));
        }

        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }
}
