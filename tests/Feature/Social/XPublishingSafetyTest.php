<?php

namespace Tests\Feature\Social;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\XPublishAttempt;
use App\Modules\Social\Services\OAuth\OAuthManager;
use App\Modules\Social\Services\SocialAccessTokenService;
use App\Modules\Social\Services\SocialPublisher;
use App\Services\ChannelPlanLimitService;
use App\Services\ClientAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class XPublishingSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        Queue::fake();
        $context = $this->createSubscribedWorkspaceContext();
        IntegrationConfig::create(['provider' => 'oauth_twitter', 'label' => 'X OAuth', 'enabled' => true, 'credentials' => ['client_id' => 'x-app', 'client_secret' => 'test-secret']]);
        $context['account'] = SocialAccount::create([
            'workspace_id' => $context['workspace']->id, 'network' => 'twitter', 'account_id' => '123',
            'name' => 'Test X', 'access_token' => 'test-token', 'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addHour(), 'meta' => ['oauth_client_id' => 'x-app'], 'active' => true,
        ]);
        $context['post'] = SocialPost::create(['workspace_id' => $context['workspace']->id, 'body' => 'Test message', 'status' => 'publishing', 'target_accounts' => [$context['account']->id]]);

        return $context;
    }

    public function test_duplicate_execution_creates_once_and_payload_is_encrypted(): void
    {
        ['post' => $post] = $this->context();
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '456']], 201)]);
        app(SocialPublisher::class)->publish($post);
        $post->update(['status' => 'publishing', 'body' => 'Changed message']);
        app(SocialPublisher::class)->publish($post);
        Http::assertSentCount(1);
        $attempt = XPublishAttempt::firstOrFail();
        $this->assertSame('Test message', $attempt->payload['body']);
        $this->assertStringNotContainsString('Test message', $attempt->getRawOriginal('payload'));
        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_ambiguous_create_is_not_retried(): void
    {
        ['post' => $post] = $this->context();
        Http::fake(['api.x.com/2/tweets' => Http::response([], 503)]);
        foreach ([1, 2] as $_) {
            try {
                app(SocialPublisher::class)->publish($post);
            } catch (\RuntimeException) {
            }
        }
        Http::assertSentCount(1);
        $this->assertSame('unknown', XPublishAttempt::firstOrFail()->status);
        $this->assertSame('unknown', $post->fresh()->publish_results[array_key_first($post->publish_results)]['status']);
    }

    public function test_crashed_create_requires_review_without_external_calls(): void
    {
        ['post' => $post, 'account' => $account] = $this->context();
        XPublishAttempt::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'workspace_id' => $post->workspace_id, 'status' => 'creating', 'payload' => $post->toArray()]);
        Http::fake();
        try {
            app(SocialPublisher::class)->publish($post);
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();
        $this->assertSame('unknown', XPublishAttempt::firstOrFail()->status);
    }

    public function test_rate_limit_schedules_retry_without_resending_early(): void
    {
        ['post' => $post] = $this->context();
        Http::fake(['api.x.com/2/tweets' => Http::response([], 429, ['Retry-After' => '120'])]);
        app(SocialPublisher::class)->publish($post);
        app(SocialPublisher::class)->publish($post);
        Http::assertSentCount(1);
        $this->assertSame(1, XPublishAttempt::firstOrFail()->retry_count);
        $this->assertSame('publishing', $post->fresh()->status);
    }

    public function test_disconnected_account_cannot_send(): void
    {
        ['post' => $post, 'account' => $account] = $this->context();
        $account->update(['active' => false]);
        Http::fake();
        try {
            app(SocialPublisher::class)->publish($post);
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();
        $this->assertSame('failed', XPublishAttempt::firstOrFail()->status);
    }

    public function test_application_change_requires_reconnect(): void
    {
        ['account' => $account] = $this->context();
        $account->update(['meta' => ['oauth_client_id' => 'old-app']]);
        $this->expectExceptionMessage('X application changed.');
        app(SocialAccessTokenService::class)->fresh($account);
    }

    public function test_refresh_rotation_is_saved_without_deleting_connection(): void
    {
        ['account' => $account] = $this->context();
        $account->update(['token_expires_at' => now()->subMinute()]);
        $oauth = Mockery::mock(OAuthManager::class);
        $oauth->shouldReceive('refresh')->once()->with('twitter', 'test-refresh')->andReturn(['access_token' => 'fresh', 'refresh_token' => 'rotated', 'expires_in' => 7200]);
        $fresh = (new SocialAccessTokenService($oauth))->fresh($account);
        $this->assertSame('rotated', $fresh->refresh_token);
        $this->assertTrue($fresh->active);
    }

    public function test_review_rejects_wrong_workspace(): void
    {
        ['user' => $user, 'post' => $post, 'account' => $account] = $this->context();
        $other = $this->createSubscribedWorkspaceContext();
        $this->actingAs($other['user'])->post(route('client.social.posts.x.review', [$post, $account]), ['action' => 'retry', 'confirm_duplicate_risk' => true])->assertForbidden();
    }

    public function test_review_verifies_author_before_recording(): void
    {
        ['user' => $user, 'post' => $post, 'account' => $account] = $this->context();
        XPublishAttempt::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'workspace_id' => $post->workspace_id, 'status' => 'unknown', 'payload' => $post->toArray()]);
        Http::fake(['api.x.com/2/tweets/456*' => Http::response(['data' => ['id' => '456', 'author_id' => '999']])]);
        $this->actingAs($user)->post(route('client.social.posts.x.review', [$post, $account]), ['action' => 'record', 'platform_post_id' => '456'])->assertUnprocessable();
        $this->assertSame('unknown', XPublishAttempt::firstOrFail()->status);
    }

    public function test_expired_subscription_blocks_provider_calls(): void
    {
        ['post' => $post] = $this->context();
        $access = Mockery::mock(ClientAccessService::class);
        $access->shouldReceive('allowsWorkspaceWrite')->andReturn(false);
        $this->app->instance(ClientAccessService::class, $access);
        Http::fake();
        try {
            app(SocialPublisher::class)->publish($post);
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();
        $this->assertSame('failed', XPublishAttempt::firstOrFail()->status);
    }

    public function test_explicit_retry_repins_edited_payload_and_retains_review_history(): void
    {
        ['user' => $user, 'post' => $post, 'account' => $account] = $this->context();
        XPublishAttempt::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'workspace_id' => $post->workspace_id, 'status' => 'failed', 'payload' => $post->toArray()]);
        $post->update(['status' => 'failed', 'body' => 'Corrected message']);
        $this->actingAs($user)->post(route('client.social.posts.x.review', [$post, $account]), ['action' => 'retry', 'confirm_duplicate_risk' => true])->assertRedirect();
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '456']], 201)]);
        app(SocialPublisher::class)->publish($post->fresh());
        Http::assertSent(fn ($request) => $request['text'] === 'Corrected message');
        $attempt = XPublishAttempt::firstOrFail();
        $this->assertSame('Test message', $attempt->review_history[0]['payload']['body']);
        $this->assertArrayNotHasKey('review_history', $attempt->toArray());
    }

    public function test_verified_review_records_post_without_another_create(): void
    {
        ['user' => $user, 'post' => $post, 'account' => $account] = $this->context();
        XPublishAttempt::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'workspace_id' => $post->workspace_id, 'status' => 'unknown', 'payload' => $post->toArray()]);
        Http::fake(['api.x.com/2/tweets/456*' => Http::response(['data' => ['id' => '456', 'author_id' => '123']])]);
        $this->actingAs($user)->post(route('client.social.posts.x.review', [$post, $account]), ['action' => 'record', 'platform_post_id' => '456'])->assertRedirect();
        app(SocialPublisher::class)->publish($post->fresh());
        Http::assertSentCount(1);
        $this->assertSame('published', $post->fresh()->status);
    }

    public function test_disconnect_preserves_unknown_receipts_and_frees_inventory_slot(): void
    {
        ['user' => $user, 'post' => $post, 'account' => $account, 'workspace' => $workspace] = $this->context();
        XPublishAttempt::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'workspace_id' => $post->workspace_id, 'status' => 'unknown', 'payload' => $post->toArray()]);
        $this->actingAs($user)->delete(route('client.social.accounts.disconnect', $account))->assertRedirect();
        $this->assertNull(SocialAccount::find($account->id));
        $tombstone = SocialAccount::withoutGlobalScope('connected')->findOrFail($account->id);
        $this->assertSame('', $tombstone->access_token);
        $this->assertNotNull($tombstone->disconnected_at);
        $this->assertSame('unknown', XPublishAttempt::firstOrFail()->status);
        $this->assertSame(0, app(ChannelPlanLimitService::class)->usage($workspace, 'social_accounts')['used']);
    }

    public function test_removed_failed_destination_does_not_poison_current_result(): void
    {
        ['post' => $post, 'account' => $account] = $this->context();
        $post->update(['publish_results' => [999 => ['status' => 'unknown', 'error' => 'Old receipt']]]);
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '456']], 201)]);
        app(SocialPublisher::class)->publish($post->fresh());
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('unknown', $post->fresh()->publish_results[999]['status']);
    }

    public function test_disconnect_during_upload_ends_waiting_instead_of_sticking_publishing(): void
    {
        ['user' => $user, 'post' => $post, 'account' => $account] = $this->context();
        XPublishAttempt::create(['post_id' => $post->id, 'social_account_id' => $account->id, 'workspace_id' => $post->workspace_id, 'status' => 'uploading', 'payload' => $post->toArray()]);
        $post->update(['publish_results' => [$account->id => ['status' => 'uploading']]]);
        $this->actingAs($user)->delete(route('client.social.accounts.disconnect', $account))->assertRedirect();
        Http::fake();
        try {
            app(SocialPublisher::class)->publish($post->fresh());
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $post->fresh()->status);
        $this->assertSame('failed', XPublishAttempt::firstOrFail()->status);
        Http::assertNothingSent();
    }
}
