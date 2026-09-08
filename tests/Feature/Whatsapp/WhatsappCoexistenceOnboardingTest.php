<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Http\Controllers\WhatsappCoexistenceController;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WhatsappCoexistenceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // Deliberately test-only routes until ingress and rollout gates are ready.
        Route::middleware(['web', 'auth'])->post('/test/coexistence/begin', [WhatsappCoexistenceController::class, 'begin']);
        Route::middleware(['web', 'auth'])->post('/test/coexistence/store', [WhatsappCoexistenceController::class, 'store']);
        config(['whatsapp.coexistence_enabled' => true]);
        Http::preventStrayRequests();
        $context = $this->createSubscribedWorkspaceContext();
        $this->actingAs($context['user']);
        IntegrationConfig::create([
            'provider' => 'meta_app', 'label' => 'Meta', 'mode' => 'live', 'enabled' => true,
            'credentials' => ['app_id' => '100', 'app_secret' => 'test-secret'],
        ]);
    }

    private function attempt(): string
    {
        return $this->postJson('/test/coexistence/begin', [])->assertOk()->json('attempt_id');
    }

    private function fakeMeta(array $phone = [], array $debug = [], array $extraPhones = []): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::response(['access_token' => 'test-token']),
            '*/debug_token*' => Http::response(['data' => array_merge([
                'app_id' => '100', 'is_valid' => true,
                'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
            ], $debug)]),
            '*/200?*' => Http::response(['id' => '200', 'name' => 'Demo']),
            '*/200/phone_numbers*' => Http::response(['data' => [array_merge([
                'id' => '300', 'display_phone_number' => '+1 555 000 0001',
                'is_on_biz_app' => true, 'platform_type' => 'CLOUD_API', 'verified_name' => 'Demo',
            ], $phone), ...$extraPhones]]),
            '*/200/subscribed_apps' => Http::response(['success' => true]),
        ]);
    }

    private function finish(string $id)
    {
        return $this->postJson('/test/coexistence/store', ['attempt_id' => $id, 'code' => 'test-code', 'waba_id' => '200']);
    }

    public function test_disabled_rollout_rejects_before_provider_calls(): void
    {
        config(['whatsapp.coexistence_enabled' => false]);
        $this->postJson('/test/coexistence/begin', [])->assertNotFound();
        $this->finish('00000000-0000-4000-8000-000000000000')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_verified_phone_connects_without_registration_or_exposing_token(): void
    {
        $this->fakeMeta();
        $response = $this->finish($this->attempt())->assertOk()->assertJsonPath('connection_mode', 'coexistence');
        $this->assertStringNotContainsString('test-token', $response->getContent());
        $this->assertSame('coexistence', WhatsappPhoneNumber::first()->connection_mode);
        $this->assertSame('test-token', WhatsappBusinessAccount::first()->credentials['access_token']);
        $this->assertDatabaseCount('channel_accounts', 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/register') || str_contains($request->url(), '/deregister'));
    }

    public function test_non_coexistence_phone_is_rejected_without_local_writes(): void
    {
        $this->fakeMeta(['is_on_biz_app' => false]);
        $this->finish($this->attempt())->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_business_accounts', 0);
        $this->assertDatabaseCount('whatsapp_phone_numbers', 0);
    }

    public function test_wrong_app_fails_closed(): void
    {
        $this->fakeMeta([], ['app_id' => '999']);
        $this->finish($this->attempt())->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_business_accounts', 0);
    }

    public function test_missing_messaging_scope_fails_closed(): void
    {
        $this->fakeMeta([], ['scopes' => ['whatsapp_business_management']]);
        $this->finish($this->attempt())->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_business_accounts', 0);
    }

    public function test_expired_attempt_does_not_contact_meta(): void
    {
        $id = $this->attempt();
        $this->travel(31)->minutes();
        $this->finish($id)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_network_failure_is_sanitized_and_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('sensitive-provider-url'));
        $id = $this->attempt();
        $response = $this->finish($id)->assertStatus(502);
        $this->assertStringNotContainsString('sensitive-provider-url', $response->getContent());
        $this->finish($id)->assertConflict();
    }

    public function test_number_is_discovered_from_meta_without_manual_entry(): void
    {
        $this->fakeMeta(['display_phone_number' => '+15550000002']);
        $this->finish($this->attempt())->assertOk();
        $this->assertSame('+15550000002', WhatsappPhoneNumber::first()->getRawOriginal('display_phone'));
    }

    public function test_multiple_eligible_numbers_are_not_silently_selected(): void
    {
        $this->fakeMeta([], [], [['id' => '301', 'display_phone_number' => '+15550000002',
            'is_on_biz_app' => true, 'platform_type' => 'CLOUD_API']]);
        $this->finish($this->attempt())->assertUnprocessable();
        $this->assertDatabaseCount('channel_accounts', 0);
    }

    public function test_meta_phone_selection_is_verified_against_waba(): void
    {
        $this->fakeMeta([], [], [['id' => '301', 'display_phone_number' => '+15550000002',
            'is_on_biz_app' => true, 'platform_type' => 'CLOUD_API']]);
        $this->postJson('/test/coexistence/store', ['attempt_id' => $this->attempt(),
            'code' => 'test-code', 'waba_id' => '200', 'phone_number_id' => '301'])->assertOk();
        $this->assertSame('301', WhatsappPhoneNumber::first()->phone_number_id);
    }

    public function test_unverified_phone_selection_is_rejected(): void
    {
        $this->fakeMeta();
        $this->postJson('/test/coexistence/store', ['attempt_id' => $this->attempt(),
            'code' => 'test-code', 'waba_id' => '200', 'phone_number_id' => '999'])->assertUnprocessable();
        $this->assertDatabaseCount('channel_accounts', 0);
    }

    public function test_workspace_switch_invalidates_attempt(): void
    {
        $id = $this->attempt();
        $context = $this->createSubscribedWorkspaceContext();
        $this->actingAs($context['user']);
        $this->finish($id)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_consumed_attempt_cannot_exchange_code_again(): void
    {
        $this->fakeMeta();
        $id = $this->attempt();
        Cache::put('whatsapp-coexistence-consumed:'.$id, true, 3600);
        $this->finish($id)->assertConflict();
        Http::assertNothingSent();
    }

    public function test_other_workspace_waba_is_never_reassigned(): void
    {
        $this->fakeMeta();
        $other = $this->createSubscribedWorkspaceContext();
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $other['workspace']->id, 'waba_id' => '200']);
        $this->finish($this->attempt())->assertConflict();
        $this->assertSame($other['workspace']->id, $waba->fresh()->workspace_id);
        $this->assertDatabaseCount('channel_accounts', 0);
    }

    public function test_reconnect_preserves_other_phones_and_channels(): void
    {
        $this->fakeMeta();
        $this->finish($this->attempt())->assertOk();
        $waba = WhatsappBusinessAccount::first();
        WhatsappPhoneNumber::create(['waba_id_fk' => $waba->id, 'phone_number_id' => '301', 'display_phone' => '+15550000002']);
        ChannelAccount::create(['workspace_id' => $waba->workspace_id, 'channel' => 'whatsapp',
            'phone_number_id' => '301', 'business_account_id' => '200', 'status' => 'active', 'display_name' => 'Other']);
        $this->finish($this->attempt())->assertOk();
        $this->assertDatabaseCount('whatsapp_phone_numbers', 2);
        $this->assertDatabaseCount('channel_accounts', 2);
    }
}
