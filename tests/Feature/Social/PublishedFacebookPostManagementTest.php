<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublishedFacebookPostManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_facebook_post_can_be_updated_on_facebook(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'facebook');

        $response = $this->actingAs($user)->put(
            route('client.social.posts.facebook.update', [$post, $account]),
            ['body' => 'Updated Facebook copy']
        );

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated Facebook copy', $post->fresh()->body);
        $this->assertSame('Updated Facebook copy', $post->fresh()->publish_results[$account->id]['edited_body']);
        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $body);

            return $request->method() === 'POST'
                && $request->url() === 'https://graph.facebook.com/v25.0/page_123'
                && ($body['message'] ?? null) === 'Updated Facebook copy'
                && ($body['access_token'] ?? null) === 'test-token';
        });
    }

    public function test_published_facebook_post_can_be_deleted_from_facebook(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'facebook');

        $response = $this->actingAs($user)->delete(
            route('client.social.posts.facebook.destroy', [$post, $account])
        );

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('social_media_post_accounts', ['post_id' => $post->id]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://graph.facebook.com/v25.0/page_123');
    }

    public function test_failed_facebook_delete_preserves_the_local_post_and_account_link(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Deletion rejected'],
        ], 400)]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'facebook');

        $response = $this->actingAs($user)->delete(
            route('client.social.posts.facebook.destroy', [$post, $account])
        );

        $response->assertRedirect()->assertSessionHasErrors('facebook');
        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseHas('social_media_post_accounts', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'published',
        ]);
    }

    public function test_instagram_cannot_use_facebook_post_management_endpoints(): void
    {
        Http::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'instagram');

        $this->actingAs($user)
            ->delete(route('client.social.posts.facebook.destroy', [$post, $account]))
            ->assertNotFound();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
        Http::assertNothingSent();
    }

    public function test_published_instagram_post_can_be_deleted_from_instagram(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'instagram');

        $response = $this->actingAs($user)->delete(
            route('client.social.posts.instagram.destroy', [$post, $account])
        );

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('social_media_post_accounts', ['post_id' => $post->id]);
        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $body);

            return $request->method() === 'DELETE'
                && $request->url() === 'https://graph.facebook.com/v25.0/page_123'
                && ($body['access_token'] ?? null) === 'test-token';
        });
    }

    public function test_failed_instagram_delete_preserves_the_local_post_and_account_link(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Deletion rejected', 'code' => 200],
        ], 400)]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'instagram');

        $response = $this->actingAs($user)->delete(
            route('client.social.posts.instagram.destroy', [$post, $account])
        );

        $response->assertRedirect()->assertSessionHasErrors('instagram');
        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseHas('social_media_post_accounts', [
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'published',
        ]);
    }

    public function test_facebook_cannot_use_instagram_post_management_endpoint(): void
    {
        Http::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post, $account] = $this->publishedPost($workspace->id, 'facebook');

        $this->actingAs($user)
            ->delete(route('client.social.posts.instagram.destroy', [$post, $account]))
            ->assertNotFound();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
        Http::assertNothingSent();
    }

    public function test_local_delete_does_not_orphan_a_live_published_post(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($workspace->id, 'facebook');

        $this->actingAs($user)
            ->delete(route('client.social.posts.destroy', $post))
            ->assertUnprocessable();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
    }

    public function test_partially_failed_post_with_a_published_target_cannot_be_deleted_locally(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        [$post] = $this->publishedPost($workspace->id, 'facebook');
        $post->update(['status' => 'failed']);

        $this->actingAs($user)
            ->delete(route('client.social.posts.destroy', $post))
            ->assertUnprocessable();

        $this->assertDatabaseHas('social_media_posts', ['id' => $post->id]);
    }

    public function test_scheduled_instagram_post_and_its_pending_target_can_be_deleted_locally(): void
    {
        Http::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $account = SocialAccount::create([
            'workspace_id' => $workspace->id,
            'network' => 'instagram',
            'account_id' => 'instagram-scheduled-account',
            'name' => 'Scheduled Instagram Account',
            'access_token' => 'test-token',
            'active' => true,
        ]);
        $post = SocialPost::create([
            'workspace_id' => $workspace->id,
            'body' => 'Scheduled Instagram post',
            'target_accounts' => [$account->id],
            'status' => 'scheduled',
            'scheduled_at' => now()->addHour(),
        ]);
        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->delete(route('client.social.posts.destroy', $post))
            ->assertRedirect()
            ->assertSessionHas('success', 'Post deleted.');

        $this->assertDatabaseMissing('social_media_posts', ['id' => $post->id]);
        $this->assertDatabaseMissing('social_media_post_accounts', ['post_id' => $post->id]);
        Http::assertNothingSent();
    }

    /** @return array{SocialPost, SocialAccount} */
    private function publishedPost(int $workspaceId, string $network): array
    {
        $account = SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => $network,
            'account_id' => $network.'-account',
            'name' => ucfirst($network).' Account',
            'access_token' => 'test-token',
            'active' => true,
        ]);

        $post = SocialPost::create([
            'workspace_id' => $workspaceId,
            'body' => 'Original copy',
            'target_accounts' => [$account->id],
            'status' => 'published',
            'published_at' => now(),
            'provider_post_id' => 'page_123',
            'publish_results' => [
                $account->id => ['status' => 'published', 'post_id' => 'page_123'],
            ],
        ]);

        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'published',
            'platform_post_id' => 'page_123',
            'published_at' => now(),
        ]);

        return [$post, $account];
    }
}
