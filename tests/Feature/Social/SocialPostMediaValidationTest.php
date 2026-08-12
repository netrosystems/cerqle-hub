<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SocialPostMediaValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_youtube_watch_page_is_rejected_with_direct_video_guidance(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id);

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'title' => 'A test video',
            'body' => 'A video update',
            'media_urls' => ['https://www.youtube.com/watch?v=5b8TY764lCE'],
            'target_accounts' => [$account->id],
            'scheduled_at' => null,
            'timezone' => 'UTC',
        ]);

        $response->assertSessionHasErrors([
            'media_urls' => 'This is a webpage link, not a direct video file. Choose Upload or use a direct public video-file URL.',
        ]);
        $this->assertDatabaseCount('social_media_posts', 0);
    }

    public function test_direct_public_video_url_is_accepted_for_youtube(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id);

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'title' => 'A test video',
            'body' => '',
            'media_urls' => ['https://cdn.example.com/videos/update.mp4'],
            'youtube_options' => [
                'privacy_status' => 'unlisted',
                'tags' => ['product', 'tutorial'],
                'category_id' => 27,
                'made_for_kids' => false,
                'notify_subscribers' => false,
            ],
            'target_accounts' => [$account->id],
            'scheduled_at' => null,
            'timezone' => 'UTC',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('social_media_posts', [
            'workspace_id' => $workspace->id,
            'status' => 'publishing',
        ]);
        $post = SocialPost::firstOrFail();
        $this->assertSame('unlisted', $post->youtube_options['privacy_status']);
        Queue::assertPushed(PublishSocialPostJob::class);
    }

    public function test_youtube_requires_a_title_and_exactly_one_video(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id);

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'title' => '',
            'body' => '',
            'media_urls' => [
                'https://cdn.example.com/videos/one.mp4',
                'https://cdn.example.com/videos/two.mp4',
            ],
            'target_accounts' => [$account->id],
            'scheduled_at' => null,
            'timezone' => 'UTC',
        ]);

        $response->assertSessionHasErrors(['title', 'media_urls']);
        $this->assertDatabaseCount('social_media_posts', 0);
    }

    public function test_playlist_requires_exactly_one_youtube_channel(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $first = $this->youtubeAccount($workspace->id);
        $second = $this->youtubeAccount($workspace->id, 'channel-456');

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'title' => 'Playlist upload',
            'body' => 'A scheduled lesson.',
            'media_urls' => ['https://cdn.example.com/videos/lesson.mp4'],
            'youtube_options' => ['playlist_id' => 'PL1234567890'],
            'target_accounts' => [$first->id, $second->id],
            'timezone' => 'UTC',
        ]);

        $response->assertSessionHasErrors(['youtube_options.playlist_id']);
        $this->assertDatabaseCount('social_media_posts', 0);
    }

    public function test_youtube_tag_limit_counts_commas_between_tags(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->youtubeAccount($workspace->id);

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'title' => 'Tag boundary test',
            'body' => '',
            'media_urls' => ['https://cdn.example.com/videos/tags.mp4'],
            'youtube_options' => ['tags' => array_fill(0, 10, str_repeat('a', 50))],
            'target_accounts' => [$account->id],
            'timezone' => 'UTC',
        ]);

        $response->assertSessionHasErrors(['youtube_options.tags']);
        $this->assertDatabaseCount('social_media_posts', 0);
    }

    private function youtubeAccount(int $workspaceId, string $channelId = 'channel-123'): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'youtube',
            'account_id' => $channelId,
            'name' => 'Test YouTube Channel',
            'access_token' => 'test-token',
            'active' => true,
        ]);
    }
}
