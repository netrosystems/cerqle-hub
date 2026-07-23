<?php

namespace Tests\Feature;

use App\Services\License\LicenseManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LicenseVerificationTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://license-domain.test',
            'app.installed' => false,
            'license.verify' => true,
            'license.server_url' => 'https://license.test',
            'license.api_key' => 'test-api-key',
            'license.product_id' => 'test-product',
            'license.verify_type' => 'envato',
            'license.verify_types' => ['envato', 'non_envato'],
        ]);

        $this->clearStoredLicense();
    }

    protected function tearDown(): void
    {
        $this->clearStoredLicense();

        parent::tearDown();
    }

    public function test_envato_is_the_default_but_both_license_types_are_available(): void
    {
        $license = app(LicenseManager::class);

        $this->assertSame('envato', $license->defaultVerifyType());
        $this->assertSame(['envato', 'non_envato'], $license->verifyTypes());
    }

    public function test_license_manager_sends_the_selected_activation_type(): void
    {
        Http::fake([
            'license.test/api/external/license/activate' => Http::response([
                'is_active' => true,
                'lic_response' => 'signed-license-data',
                'message' => 'License activated.',
            ]),
        ]);

        $result = app(LicenseManager::class)->activate('SHOP-LICENSE-CODE', 'Client Name', 'envato');

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => $request->url() === 'https://license.test/api/external/license/activate'
            && $request['product_id'] === 'test-product'
            && $request['license_code'] === 'SHOP-LICENSE-CODE'
            && $request['verify_type'] === 'envato');
    }

    public function test_installer_license_activation_accepts_non_envato_mode(): void
    {
        Http::fake([
            'license.test/api/external/license/activate' => Http::response([
                'is_active' => true,
                'lic_response' => 'signed-license-data',
                'message' => 'License activated.',
            ]),
        ]);

        $response = $this->postJson(route('install.activate-license'), [
            'license_code' => 'SHOP-LICENSE-CODE',
            'client_name' => 'Cerqle',
            'verify_type' => 'non_envato',
        ]);

        $response->assertOk();
        Http::assertSent(fn ($request) => $request['verify_type'] === 'non_envato');
    }

    private function clearStoredLicense(): void
    {
        @unlink(storage_path('app/.license'));
        @unlink(storage_path('app/.license_code'));
        @unlink(storage_path('app/.license_type'));
    }
}
