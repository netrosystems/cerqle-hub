<?php

namespace Tests\Feature\Social;

use App\Models\Media;
use App\Models\User;
use App\Modules\Social\Jobs\PurgeTemporarySocialMediaJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\SocialMediaLifecycleService;
use App\Modules\Social\Services\SocialPublisher;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SocialMediaLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_post_releases_quota_and_purges_after_24_hours(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $post = $this->socialPost($workspace->id, 'published');
        $media = $this->media($user, 'media/video.mp4');
        Storage::disk('public')->put($media->path, 'video');
        $post->media()->attach($media);

        app(SocialMediaLifecycleService::class)->releaseAfterSuccessfulPublish($post);

        $media->refresh();
        $this->assertNotNull($media->quota_released_at);
        $this->assertNotNull($post->fresh()->temporary_media_released_at);
        $this->assertTrue($media->purge_after->between(now()->addHours(23), now()->addHours(25)));
        $this->assertSame(0, app(MediaService::class)->usedBytes($user));

        $media->update(['purge_after' => now()->subMinute()]);
        (new PurgeTemporarySocialMediaJob)->handle();
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing('media/video.mp4');
    }

    public function test_failed_reference_prevents_release_and_cleanup(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $published = $this->socialPost($workspace->id, 'published');
        $failed = $this->socialPost($workspace->id, 'failed');
        $media = $this->media($user, 'media/shared.mp4');
        Storage::disk('public')->put($media->path, 'video');
        $published->media()->attach($media);
        $failed->media()->attach($media);

        app(SocialMediaLifecycleService::class)->releaseAfterSuccessfulPublish($published);
        $this->assertNull($media->fresh()->quota_released_at);

        $media->update(['purge_after' => now()->subMinute()]);
        (new PurgeTemporarySocialMediaJob)->handle();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_deleting_last_active_reference_releases_media_shared_with_published_history(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $published = $this->socialPost($workspace->id, 'published');
        $failed = $this->socialPost($workspace->id, 'failed');
        $media = $this->media($user, 'media/shared-delete.mp4');
        $published->media()->attach($media);
        $failed->media()->attach($media);

        app(SocialMediaLifecycleService::class)->detachDeletedPost($failed);

        $this->assertNotNull($media->fresh()->quota_released_at);
        $this->assertNotNull($media->fresh()->purge_after);
        $this->assertNotNull($published->fresh()->temporary_media_released_at);
    }

    public function test_orphaned_temporary_upload_is_purged(): void
    {
        Storage::fake('public');
        ['user' => $user] = $this->createWorkspaceContext();
        $media = $this->media($user, 'media/orphan.mp4');
        Storage::disk('public')->put($media->path, 'video');
        $media->update(['purge_after' => now()->subMinute()]);

        (new PurgeTemporarySocialMediaJob)->handle();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_client_storage_usage_is_shared_across_team_members(): void
    {
        ['user' => $owner, 'client' => $client] = $this->createWorkspaceContext();
        $member = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);
        $this->media($owner, 'media/owner.mp4')->update(['size_bytes' => 1024]);
        $this->media($member, 'media/member.mp4')->update(['size_bytes' => 2048]);

        $this->assertSame(3072, app(MediaService::class)->usedBytes($owner));
        $this->assertSame(3072, app(MediaService::class)->usedBytes($member));
    }

    public function test_posts_index_exposes_uploaded_media_mime_types_for_previews(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $media = $this->media($user, 'media/preview-video');
        $media->update(['mime_type' => 'video/mp4']);
        Storage::disk('public')->put($media->path, 'video');
        $post = $this->socialPost($workspace->id, 'scheduled');
        $post->update(['media_urls' => [$media->url()]]);
        $post->media()->attach($media);

        $this->actingAs($user)
            ->get(route('client.social.posts.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Social/Posts/Index')
                ->where('posts.data.0.media_mime_types', [$media->url() => 'video/mp4'])
            );
    }

    public function test_youtube_media_is_released_only_after_processing_completes(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [[
            'status' => ['uploadStatus' => 'processed'],
            'processingDetails' => ['processingStatus' => 'succeeded'],
        ]]])]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = SocialAccount::create([
            'workspace_id' => $workspace->id,
            'network' => 'youtube',
            'account_id' => 'channel-1',
            'name' => 'YouTube',
            'access_token' => 'token',
            'active' => true,
        ]);
        $post = $this->socialPost($workspace->id, 'publishing');
        $post->update([
            'target_accounts' => [$account->id],
            'publish_results' => [$account->id => ['status' => 'processing', 'post_id' => 'video-1']],
        ]);
        SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'processing',
            'platform_post_id' => 'video-1',
        ]);
        $media = $this->media($user, 'media/processing.mp4');
        $post->media()->attach($media);

        $this->assertTrue(app(SocialPublisher::class)->confirmProcessing($post));
        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('published', $post->accountLinks()->first()->status);
        $this->assertNotNull($media->fresh()->quota_released_at);
    }

    private function socialPost(int $workspaceId, string $status): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId,
            'body' => 'Test',
            'media_urls' => [],
            'target_accounts' => [],
            'status' => $status,
        ]);
    }

    private function media(User $user, string $path): Media
    {
        return Media::factory()->create([
            'mediable_type' => User::class,
            'mediable_id' => $user->id,
            'disk' => 'public',
            'path' => $path,
            'size_bytes' => 1024,
            'collection' => 'social-video',
            'is_temporary' => true,
        ]);
    }
}
