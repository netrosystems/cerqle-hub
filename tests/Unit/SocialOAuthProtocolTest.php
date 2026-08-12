<?php

namespace Tests\Unit;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\Credentials\OAuthClientCredentials;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SocialOAuthProtocolTest extends TestCase
{
    public function test_youtube_authorization_requests_offline_access_and_playlist_scope(): void
    {
        $url = $this->managerFor('youtube')->getAuthUrl('youtube', 1, 'https://app.test/callback');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('https://www.googleapis.com/auth/youtube', $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('true', $query['include_granted_scopes']);
    }

    public function test_google_code_exchange_is_form_encoded(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'google-access',
            'refresh_token' => 'google-refresh',
            'expires_in' => 3600,
        ])]);

        $tokens = $this->managerFor('youtube')->exchangeCode('youtube', 'auth-code', 'https://app.test/callback');

        $this->assertSame('google-refresh', $tokens['refresh_token']);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && str_starts_with((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $request['code'] === 'auth-code'
                && $request['grant_type'] === 'authorization_code';
        });
    }

    public function test_tiktok_exchange_uses_v2_form_contract_and_top_level_response(): void
    {
        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'tiktok-access',
            'refresh_token' => 'tiktok-refresh',
            'expires_in' => 86400,
            'open_id' => 'creator-1',
        ])]);

        $tokens = $this->managerFor('tiktok')->exchangeCode('tiktok', 'auth-code', 'https://app.test/callback');

        $this->assertSame('tiktok-access', $tokens['access_token']);
        $this->assertSame('creator-1', $tokens['open_id']);
        Http::assertSent(function (Request $request) {
            return str_starts_with((string) $request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $request['code'] === 'auth-code'
                && ! isset($request['auth_code']);
        });
    }

    public function test_meta_exchange_extends_short_token_before_page_discovery(): void
    {
        Http::fakeSequence('*graph.facebook.com/v25.0/oauth/access_token*')
            ->push(['access_token' => 'short-token', 'expires_in' => 3600])
            ->push(['access_token' => 'long-token', 'expires_in' => 5_184_000]);

        $tokens = $this->managerFor('facebook')->exchangeCode('facebook', 'auth-code', 'https://app.test/callback');

        $this->assertSame('long-token', $tokens['access_token']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ($request['fb_exchange_token'] ?? null) === 'short-token'
            && $request['grant_type'] === 'fb_exchange_token');
    }

    public function test_linkedin_oidc_profile_and_publish_response_headers_are_used(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'member-1', 'name' => 'Ada Lovelace', 'picture' => 'https://cdn.test/ada.jpg',
            ]),
            'api.linkedin.com/v2/ugcPosts' => Http::response([], 201, ['X-RestLi-Id' => 'urn:li:share:123']),
        ]);

        $driver = new LinkedInDriver;
        $profile = $driver->fetchAccountInfo('token');
        $this->assertSame('member-1', $profile['account_id']);

        $account = new SocialAccount(['account_id' => 'member-1', 'access_token' => 'token']);
        $this->assertSame('urn:li:share:123', $driver->publish($account, ['body' => 'Hello']));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.linkedin.com/v2/ugcPosts'
            && $request->header('X-Restli-Protocol-Version')[0] === '2.0.0');
    }

    private function managerFor(string $network): OAuthManager
    {
        $resolver = Mockery::mock(CredentialResolver::class);
        $resolver->shouldReceive('oauth')->once()->with($network)->andReturn(new OAuthClientCredentials([
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
        ]));

        return new OAuthManager($resolver);
    }
}
