<?php

namespace Tests\Feature\Social;

use App\Models\User;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Models\SocialAccount;
use App\Services\ChannelPlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class XOAuthConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $context = $this->createSubscribedWorkspaceContext();
        IntegrationConfig::create(['provider' => 'oauth_twitter', 'label' => 'X OAuth', 'enabled' => true, 'credentials' => ['client_id' => 'x-app', 'client_secret' => 'test-secret']]);
        $this->actingAs($context['user']);
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'test-access', 'refresh_token' => 'test-refresh', 'expires_in' => 7200, 'scope' => 'tweet.read tweet.write users.read media.write offline.access']),
            'api.x.com/2/users/me*' => Http::response(['data' => ['id' => '123', 'name' => 'Test X', 'username' => 'test_x']]),
        ]);

        return $context;
    }

    private function start(): array
    {
        $response = $this->get(route('client.social.accounts.connect', 'twitter'));
        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_pkce_parallel_attempts_and_single_use_callback(): void
    {
        ['workspace' => $workspace] = $this->context();
        $first = $this->start();
        $second = $this->start();
        $this->assertNotSame($first['state'], $second['state']);
        $this->assertSame('S256', $first['code_challenge_method']);
        $attempt = Session::get('social_oauth_attempts.'.$first['state']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $attempt['verifier'], true)), '+/', '-_'), '='), $first['code_challenge']);
        $url = route('client.social.oauth.callback', 'twitter').'?'.http_build_query(['code' => 'test-code', 'state' => $first['state']]);
        $this->get($url)->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('social_media_accounts', ['workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => '123']);
        $this->get($url)->assertRedirect()->assertSessionHas('error');
        Http::assertSentCount(2);
        $this->assertNotNull(Session::get('social_oauth_attempts.'.$second['state']));
        $account = SocialAccount::firstOrFail();
        $this->assertSame('x-app', $account->meta['oauth_client_id']);
        $this->assertArrayNotHasKey('access_token', $account->toArray());
    }

    public function test_expired_attempt_and_provider_cancellation_make_no_calls(): void
    {
        $this->context();
        $query = $this->start();
        Session::put('social_oauth_attempts.'.$query['state'].'.expires_at', now()->subSecond()->timestamp);
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('error');
        $query = $this->start();
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query(['state' => $query['state'], 'error' => 'access_denied']))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_changed_application_rejects_exchange(): void
    {
        $this->context();
        $query = $this->start();
        IntegrationConfig::where('provider', 'oauth_twitter')->firstOrFail()->update(['credentials' => ['client_id' => 'other-app', 'client_secret' => 'test-secret']]);
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_missing_scopes_do_not_persist_account(): void
    {
        $this->context();
        Http::swap(new Factory);
        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['access_token' => 'test', 'refresh_token' => 'test-refresh', 'scope' => 'tweet.read users.read'])]);
        $query = $this->start();
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('error');
        $this->assertDatabaseCount('social_media_accounts', 0);
    }

    public function test_removed_workspace_access_prevents_exchange(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->context();
        $query = $this->start();
        $workspace->members()->detach($user->id);
        $workspace->update(['owner_id' => User::factory()->create()->id]);
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']))->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_explicit_disconnect_reconnects_same_tombstone_and_uses_one_slot(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->context();
        $plan = $client->effectivePlan();
        $plan->update(['limits' => array_merge($plan->limits ?? [], ['social_accounts' => 1])]);
        $account = SocialAccount::create([
            'workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => '123',
            'name' => 'Original X', 'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'active' => true,
        ]);
        $this->delete(route('client.social.accounts.disconnect', $account))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(0, SocialAccount::count());
        $tombstone = SocialAccount::withoutGlobalScope('connected')->findOrFail($account->id);
        $this->assertNotNull($tombstone->disconnected_at);
        $this->assertFalse($tombstone->active);
        $this->assertSame('', $tombstone->access_token);
        $this->assertSame(0, app(ChannelPlanLimitService::class)->usage($workspace, 'social_accounts')['used']);

        $query = $this->start();
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query([
            'state' => $query['state'], 'code' => 'reconnect-code',
        ]))->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();

        $reconnected = SocialAccount::firstOrFail();
        $this->assertSame($account->id, $reconnected->id);
        $this->assertNull($reconnected->disconnected_at);
        $this->assertTrue($reconnected->active);
        $this->assertSame('test-access', $reconnected->access_token);
        $this->assertSame('test-refresh', $reconnected->refresh_token);
        $this->assertSame(1, SocialAccount::withoutGlobalScope('connected')->count());
        $usage = app(ChannelPlanLimitService::class)->usage($workspace, 'social_accounts');
        $this->assertSame(1, $usage['used']);
        $this->assertTrue($usage['is_full']);
    }

    public function test_connected_x_reauthorization_at_capacity_keeps_same_row(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->context();
        $plan = $client->effectivePlan();
        $plan->update(['limits' => array_merge($plan->limits ?? [], ['social_accounts' => 1])]);
        $account = SocialAccount::create([
            'workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => '123',
            'name' => 'Original X', 'access_token' => 'old-access', 'active' => true,
        ]);
        $this->assertTrue(app(ChannelPlanLimitService::class)->usage($workspace, 'social_accounts')['is_full']);
        $query = $this->start();
        $this->get(route('client.social.oauth.callback', 'twitter').'?'.http_build_query([
            'state' => $query['state'], 'code' => 'reauth-code',
        ]))->assertRedirect()->assertSessionHas('success')->assertSessionHasNoErrors();
        $this->assertSame($account->id, SocialAccount::firstOrFail()->id);
        $this->assertSame('test-access', $account->fresh()->access_token);
        $this->assertSame(1, SocialAccount::withoutGlobalScope('connected')->count());
        $this->assertSame(1, app(ChannelPlanLimitService::class)->usage($workspace, 'social_accounts')['used']);
    }
}
