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
            'app.installed' => false,
            'license.verify' => true,
            'license.server_url' => 'https://license.test',
            'license.api_key' => 'test-api-key',
            'license.product_id' => 'test-product',
            'license.verify_type' => 'non_envato',
            'license.verify_types' => ['non_envato'],
        ]);

        $this->clearStoredLicense();
    }

    protected function tearDown(): void
    {
        $this->clearStoredLicense();

        parent::tearDown();
    }

    public function test_shop_license_mode_is_the_only_configured_activation_type(): void
    {
        $license = app(LicenseManager::class);

        $this->assertSame('non_envato', $license->defaultVerifyType());
        $this->assertSame(['non_envato'], $license->verifyTypes());
    }

    public function test_license_manager_coerces_unoffered_activation_types_to_shop_license_mode(): void
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
            && $request['verify_type'] === 'non_envato');
    }

    public function test_installer_license_activation_rejects_unconfigured_envato_mode(): void
    {
        Http::fake();

        $response = $this->postJson(route('install.activate-license'), [
            'license_code' => 'ENVATO-CODE',
            'client_name' => 'Envato Buyer',
            'verify_type' => 'envato',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('verify_type');
        Http::assertNothingSent();
    }

    private function clearStoredLicense(): void
    {
        @unlink(storage_path('app/.license'));
        @unlink(storage_path('app/.license_code'));
        @unlink(storage_path('app/.license_type'));
    }
}
