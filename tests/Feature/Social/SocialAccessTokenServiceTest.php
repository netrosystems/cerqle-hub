<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\OAuth\OAuthManager;
use App\Modules\Social\Services\SocialAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SocialAccessTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refreshes_an_expiring_youtube_token_and_keeps_the_connection_active(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id, now()->subMinute());

        $oauth = Mockery::mock(OAuthManager::class);
        $oauth->shouldReceive('refresh')
            ->once()
            ->with('youtube', 'persistent-refresh-token')
            ->andReturn([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'persistent-refresh-token',
                'expires_in' => 3600,
            ]);

        $fresh = (new SocialAccessTokenService($oauth))->fresh($account);

        $this->assertSame('fresh-access-token', $fresh->access_token);
        $this->assertSame('persistent-refresh-token', $fresh->refresh_token);
        $this->assertTrue($fresh->active);
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    public function test_it_does_not_refresh_a_youtube_token_that_is_not_near_expiry(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id, now()->addMinutes(30));

        $oauth = Mockery::mock(OAuthManager::class);
        $oauth->shouldNotReceive('refresh');

        $fresh = (new SocialAccessTokenService($oauth))->fresh($account);

        $this->assertTrue($fresh->is($account));
        $this->assertSame('current-access-token', $fresh->access_token);
    }

    public function test_a_transient_refresh_failure_does_not_disable_or_delete_the_connection(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id, now()->subMinute());

        $oauth = Mockery::mock(OAuthManager::class);
        $oauth->shouldReceive('refresh')
            ->once()
            ->andThrow(new \RuntimeException('Google is temporarily unavailable.'));

        try {
            (new SocialAccessTokenService($oauth))->fresh($account);
            $this->fail('The refresh failure should be reported to the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google is temporarily unavailable.', $e->getMessage());
        }

        $unchanged = $account->fresh();
        $this->assertNotNull($unchanged);
        $this->assertTrue($unchanged->active);
        $this->assertSame('current-access-token', $unchanged->access_token);
        $this->assertSame('persistent-refresh-token', $unchanged->refresh_token);
    }

    private function youtubeAccount(int $workspaceId, mixed $expiresAt): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'youtube',
            'account_id' => 'channel-123',
            'name' => 'Test channel',
            'access_token' => 'current-access-token',
            'refresh_token' => 'persistent-refresh-token',
            'token_expires_at' => $expiresAt,
            'active' => true,
        ]);
    }
}
