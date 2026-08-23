<?php

namespace Tests\Unit;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\Credentials\OAuthClientCredentials;
use App\Modules\Social\Services\OAuth\OAuthManager;
use Mockery;
use Tests\TestCase;

class MetaSocialOAuthScopeTest extends TestCase
{
    public function test_facebook_connect_requests_business_management(): void
    {
        $scopes = $this->scopesFor('facebook');

        $this->assertContains('pages_show_list', $scopes);
        $this->assertContains('pages_manage_posts', $scopes);
        $this->assertContains('business_management', $scopes);
    }

    public function test_instagram_connect_requests_business_management(): void
    {
        $scopes = $this->scopesFor('instagram');

        $this->assertContains('pages_show_list', $scopes);
        $this->assertContains('instagram_basic', $scopes);
        $this->assertContains('instagram_content_publish', $scopes);
        $this->assertContains('instagram_manage_comments', $scopes);
        $this->assertNotContains('instagram_manage_contents', $scopes);
        $this->assertContains('pages_read_engagement', $scopes);
        $this->assertContains('business_management', $scopes);
    }

    /** @return list<string> */
    private function scopesFor(string $network): array
    {
        $resolver = Mockery::mock(CredentialResolver::class);
        $resolver->shouldReceive('oauth')
            ->once()
            ->with($network)
            ->andReturn(new OAuthClientCredentials([
                'client_id' => 'meta-app-id',
                'client_secret' => 'meta-app-secret',
            ]));

        $url = (new OAuthManager($resolver))->getAuthUrl(
            $network,
            1,
            'https://cerqle.test/app/social/accounts/callback/'.$network,
        );

        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return explode(',', (string) ($query['scope'] ?? ''));
    }
}
