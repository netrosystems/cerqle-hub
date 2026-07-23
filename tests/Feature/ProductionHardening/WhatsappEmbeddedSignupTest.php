<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\Whatsapp\Http\Controllers\WhatsappEmbeddedSignupController;
use App\Modules\Whatsapp\Http\Controllers\WhatsappSetupController;
use App\Modules\Inbox\Http\Controllers\InboxSetupController;
use App\Modules\Integrations\Services\Credentials\MetaCredentials;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappEmbeddedSignupTest extends TestCase
{
    use RefreshDatabase;

    public function test_already_registered_phone_is_treated_as_idempotent(): void
    {
        Http::fake([
            'graph.facebook.com/*/register' => Http::response([
                'error' => ['message' => 'Phone number is already registered'],
            ], 400),
        ]);

        $this->assertTrue($this->registerNumber());
    }

    public function test_registration_failure_is_not_treated_as_success(): void
    {
        Http::fake([
            'graph.facebook.com/*/register' => Http::response([
                'error' => ['message' => 'Two-step verification PIN is incorrect'],
            ], 400),
        ]);

        $this->assertFalse($this->registerNumber());
    }

    public function test_waba_can_be_discovered_when_meta_omits_the_browser_session_message(): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response([
                'data' => [
                    'granular_scopes' => [
                        ['scope' => 'pages_show_list', 'target_ids' => ['PAGE_123']],
                        ['scope' => 'whatsapp_business_management', 'target_ids' => ['WABA_456']],
                    ],
                ],
            ]),
        ]);

        $method = new \ReflectionMethod(WhatsappEmbeddedSignupController::class, 'discoverWabaId');
        $method->setAccessible(true);

        $wabaId = $method->invoke(
            new WhatsappEmbeddedSignupController,
            'USER_TOKEN',
            new MetaCredentials(['app_id' => 'APP_ID', 'app_secret' => 'APP_SECRET']),
        );

        $this->assertSame('WABA_456', $wabaId);
    }

    public function test_meta_phone_sync_prunes_stale_local_phone_numbers(): void
    {
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => 7,
            'waba_id' => 'WABA_123',
            'credentials' => ['system_user_token' => 'TOKEN'],
        ]);

        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PHONE_OLD',
            'display_phone' => '+1 555-466-1680',
            'verified_name' => 'Old test number',
        ]);

        ChannelAccount::create([
            'workspace_id' => 7,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'Old test number',
            'phone_number_id' => 'PHONE_OLD',
            'business_account_id' => 'WABA_123',
            'status' => 'active',
        ]);

        Http::fake([
            'graph.facebook.com/v25.0/WABA_123/phone_numbers*' => Http::response([
                'data' => [[
                    'id' => 'PHONE_KEEP',
                    'display_phone_number' => '+44 7423 303734',
                    'verified_name' => 'ProSMS',
                    'quality_rating' => 'GREEN',
                ]],
            ], 200),
            'graph.facebook.com/v25.0/PHONE_KEEP*' => Http::response([
                'id' => 'PHONE_KEEP',
                'display_phone_number' => '+44 7423 303734',
                'verified_name' => 'ProSMS',
                'quality_rating' => 'GREEN',
                'name_status' => 'APPROVED',
                'account_mode' => 'LIVE',
            ], 200),
        ]);

        $method = new \ReflectionMethod(WhatsappSetupController::class, 'importPhoneNumbersFromMeta');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke(new WhatsappSetupController, $waba));

        $this->assertDatabaseHas('whatsapp_phone_numbers', [
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PHONE_KEEP',
            'display_phone' => '+44 7423 303734',
        ]);
        $this->assertDatabaseMissing('whatsapp_phone_numbers', ['phone_number_id' => 'PHONE_OLD']);
        $this->assertDatabaseMissing('channel_accounts', ['phone_number_id' => 'PHONE_OLD']);
    }

    public function test_embedded_signup_sync_keeps_only_selected_whatsapp_phone_number(): void
    {
        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => 7,
            'waba_id' => 'WABA_123',
            'credentials' => [
                'system_user_token' => 'TOKEN',
                'registration_pin' => '123456',
            ],
        ]);

        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => 'PHONE_OLD',
            'display_phone' => '+1 555-466-1680',
            'verified_name' => 'Old test number',
        ]);

        ChannelAccount::create([
            'workspace_id' => 7,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'Old test number',
            'phone_number_id' => 'PHONE_OLD',
            'business_account_id' => 'WABA_123',
            'status' => 'active',
        ]);

        Http::fake([
            'graph.facebook.com/v25.0/WABA_123/phone_numbers*' => Http::response([
                'data' => [
                    ['id' => 'PHONE_OLD', 'display_phone_number' => '+1 555-466-1680', 'verified_name' => 'Old test number'],
                    ['id' => 'PHONE_KEEP', 'display_phone_number' => '+44 7423 303734', 'verified_name' => 'ProSMS'],
                ],
            ], 200),
            'graph.facebook.com/v25.0/PHONE_KEEP*' => Http::response([
                'id' => 'PHONE_KEEP',
                'display_phone_number' => '+44 7423 303734',
                'verified_name' => 'ProSMS',
                'quality_rating' => 'GREEN',
                'name_status' => 'APPROVED',
                'account_mode' => 'LIVE',
            ], 200),
            'graph.facebook.com/*/register' => Http::response(['success' => true], 200),
        ]);

        $method = new \ReflectionMethod(WhatsappEmbeddedSignupController::class, 'syncPhoneNumbers');
        $method->setAccessible(true);

        $count = $method->invoke(
            new WhatsappEmbeddedSignupController,
            $waba,
            'TOKEN',
            new MetaCredentials(['app_id' => 'APP_ID', 'app_secret' => 'APP_SECRET']),
            'PHONE_KEEP',
        );

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('whatsapp_phone_numbers', ['phone_number_id' => 'PHONE_KEEP']);
        $this->assertDatabaseMissing('whatsapp_phone_numbers', ['phone_number_id' => 'PHONE_OLD']);
        $this->assertDatabaseMissing('channel_accounts', ['phone_number_id' => 'PHONE_OLD']);
    }

    public function test_social_page_filter_keeps_only_meta_selected_assets(): void
    {
        $pages = [
            ['id' => 'PAGE_A', 'name' => 'Wrong Page', 'instagram_business_account' => ['id' => 'IG_A']],
            ['id' => 'PAGE_B', 'name' => 'Selected Page', 'instagram_business_account' => ['id' => 'IG_B']],
            ['id' => 'PAGE_C', 'name' => 'Selected By IG', 'instagram_business_account' => ['id' => 'IG_C']],
        ];

        $method = new \ReflectionMethod(InboxSetupController::class, 'filterPagesToSelectedTargets');
        $method->setAccessible(true);

        $filtered = $method->invoke(new InboxSetupController(app(\App\Modules\Integrations\Services\MetaPageDiscoveryService::class)), $pages, ['PAGE_B'], ['IG_C']);

        $this->assertSame(['PAGE_B', 'PAGE_C'], array_column($filtered, 'id'));
    }

    private function registerNumber(): bool
    {
        $method = new \ReflectionMethod(WhatsappEmbeddedSignupController::class, 'registerNumber');
        $method->setAccessible(true);

        return $method->invoke(
            new WhatsappEmbeddedSignupController,
            'PHONE_123',
            'TOKEN',
            'WABA_123',
            '123456',
        );
    }
}
