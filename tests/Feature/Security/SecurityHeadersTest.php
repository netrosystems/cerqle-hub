<?php

namespace Tests\Feature\Security;

use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_scripts_are_nonce_protected_in_production(): void
    {
        config(['app.env' => 'production']);
        config(['services.onesignal.app_id' => 'synthetic-public-app-id']);
        IntegrationConfig::create(['provider' => 'meta_app', 'label' => 'Synthetic Meta app', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['app_id' => '123456789', 'app_secret' => 'synthetic-test-secret']]);
        $response = $this->get('/login')->assertOk();
        $this->assertStringContainsString('OneSignal.init', $response->getContent());
        $this->assertStringContainsString('FB.init', $response->getContent());
        $csp = $response->headers->get('Content-Security-Policy');
        preg_match('/script-src ([^;]+)/', $csp, $directive);
        $this->assertStringNotContainsString('unsafe-inline', $directive[1]);
        $this->assertStringNotContainsString('unsafe-eval', $directive[1]);
        preg_match("/'nonce-([^']+)'/", $directive[1], $nonce);
        preg_match_all('/<script\b([^>]*)>/', $response->getContent(), $scripts);
        foreach ($scripts[1] as $attributes) {
            if (! str_contains($attributes, 'src=')) {
                $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes);
            }
        }
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }
}
