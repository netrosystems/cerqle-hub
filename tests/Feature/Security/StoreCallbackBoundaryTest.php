<?php

namespace Tests\Feature\Security;

use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\StoreConnectionTester;
use App\Modules\Ecommerce\Services\StoreConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreCallbackBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $ctx = $this->createWorkspaceContext();
        $ctx['store'] = EcommerceStore::create([
            'workspace_id' => $ctx['workspace']->id, 'platform' => 'woocommerce', 'domain' => 'https://8.8.8.8',
            'name' => 'Test Store', 'status' => 'connected', 'credentials' => ['consumer_key' => 'original', 'consumer_secret' => 'original-secret'],
        ]);

        return $ctx;
    }

    private function attempt(array $ctx, string $token, bool $expired = false): void
    {
        DB::table('ecommerce_oauth_attempts')->insert([
            'user_id' => $ctx['user']->id, 'workspace_id' => $ctx['workspace']->id, 'store_id' => $ctx['store']->id,
            'token_hash' => hash('sha256', $token), 'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_store_uuid_is_not_callback_authorization(): void
    {
        $ctx = $this->context();
        $this->mock(StoreConnector::class)->shouldNotReceive('connect');
        $this->postJson(route('webhooks.ecommerce.woo_auth'), ['user_id' => $ctx['store']->uuid, 'consumer_key' => 'attacker', 'consumer_secret' => 'attacker'])->assertStatus(400);
        $this->assertSame('original', $ctx['store']->fresh()->credentials['consumer_key']);
    }

    public function test_callback_attempt_is_single_use(): void
    {
        $ctx = $this->context();
        $this->attempt($ctx, 'one-time');
        $this->mock(StoreConnector::class)->shouldReceive('connect')->once()->with(
            $ctx['workspace']->id, 'woocommerce', $ctx['store']->domain, ['consumer_key' => 'new', 'consumer_secret' => 'new-secret']
        )->andReturn(['ok' => true, 'message' => 'Connected', 'store' => $ctx['store']]);
        $payload = ['user_id' => 'one-time', 'consumer_key' => 'new', 'consumer_secret' => 'new-secret'];
        $this->postJson(route('webhooks.ecommerce.woo_auth'), $payload)->assertOk();
        $this->postJson(route('webhooks.ecommerce.woo_auth'), $payload)->assertStatus(400);
    }

    public function test_expired_attempt_and_inactive_actor_are_rejected(): void
    {
        $ctx = $this->context();
        $this->attempt($ctx, 'expired', true);
        $this->mock(StoreConnector::class)->shouldNotReceive('connect');
        $this->postJson(route('webhooks.ecommerce.woo_auth'), ['user_id' => 'expired', 'consumer_key' => 'new', 'consumer_secret' => 'new'])->assertStatus(400);
        $this->attempt($ctx, 'inactive');
        $ctx['user']->update(['status' => 'inactive']);
        $this->postJson(route('webhooks.ecommerce.woo_auth'), ['user_id' => 'inactive', 'consumer_key' => 'new', 'consumer_secret' => 'new'])->assertStatus(400);
    }

    public function test_failed_reconnect_does_not_overwrite_working_credentials(): void
    {
        $ctx = $this->context();
        $this->mock(StoreConnectionTester::class)->shouldReceive('test')->once()->andReturn(['ok' => false, 'message' => 'Rejected']);
        $result = app(StoreConnector::class)->connect($ctx['workspace']->id, 'woocommerce', $ctx['store']->domain,
            ['consumer_key' => 'invalid', 'consumer_secret' => 'invalid']);
        $this->assertFalse($result['ok']);
        $this->assertSame('original', $ctx['store']->fresh()->credentials['consumer_key']);
        $this->assertSame('connected', $ctx['store']->fresh()->status);
    }
}
