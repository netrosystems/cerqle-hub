<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\Social\Models\SocialPost;
use App\Services\MediaService;
use App\Services\UploadLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        return User::factory()->create([
            'role' => 'client',
            'email_verified_at' => now(),
        ]);
    }

    public function test_user_can_view_media_library(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user)
            ->get(route('client.media.index'))
            ->assertOk();
    }

    public function test_user_can_upload_file(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $file = UploadedFile::fake()->image('avatar.jpg', 100, 100);

        $this->actingAs($user)
            ->postJson(route('client.media.store'), ['file' => $file])
            ->assertCreated()
            ->assertJsonStructure(['id', 'filename', 'url', 'size_bytes']);

        $this->assertDatabaseHas('media', [
            'mediable_type' => User::class,
            'mediable_id' => $user->id,
        ]);
    }

    public function test_unsafe_media_type_returns_a_clear_validation_error(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();
        $file = UploadedFile::fake()->create('payload.svg', 10, 'image/svg+xml');

        $this->actingAs($user)
            ->postJson(route('client.media.store'), ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_storage_quota_uses_the_plan_storage_limit_in_megabytes(): void
    {
        $user = $this->clientUser();
        $plan = Plan::factory()->create(['limits' => ['storage' => 5120]]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'gateway' => 'manual',
            'starts_at' => now(),
        ]);

        $this->assertSame(
            5120 * 1024 * 1024,
            app(MediaService::class)->quotaBytes($user->fresh()),
        );
    }

    public function test_social_video_application_limit_is_500_megabytes(): void
    {
        $this->assertSame(500, app(UploadLimitService::class)->youtubeVideoMaxMegabytes());
        $this->assertSame(500 * 1024, app(UploadLimitService::class)->youtubeVideoMaxKilobytes());
    }

    public function test_499_megabyte_social_video_passes_application_upload_validation(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $this->actingAs($user)
            ->postJson(route('client.media.store'), [
                'file' => UploadedFile::fake()->create('video.mp4', 499 * 1024, 'video/mp4'),
                'collection' => 'social-video',
            ])
            ->assertCreated();
    }

    public function test_social_video_above_500_megabytes_is_rejected(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();

        $this->actingAs($user)
            ->postJson(route('client.media.store'), [
                'file' => UploadedFile::fake()->create('video.mp4', (500 * 1024) + 1, 'video/mp4'),
                'collection' => 'social-video',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_upload_is_rejected_when_remaining_plan_storage_is_too_small(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();
        $plan = Plan::factory()->create(['limits' => ['storage' => 1]]);
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'gateway' => 'manual',
            'starts_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson(route('client.media.store'), [
                'file' => UploadedFile::fake()->create('video.mp4', 1025, 'video/mp4'),
                'collection' => 'social-video',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_media_referenced_by_an_active_social_post_cannot_be_deleted(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $media = Media::factory()->create([
            'mediable_type' => User::class,
            'mediable_id' => $user->id,
            'disk' => 'public',
            'path' => 'media/in-use.mp4',
            'is_temporary' => true,
        ]);
        $post = SocialPost::create([
            'workspace_id' => $workspace->id,
            'body' => 'Scheduled',
            'target_accounts' => [],
            'status' => 'scheduled',
        ]);
        $post->media()->attach($media);

        $this->actingAs($user)
            ->deleteJson(route('client.media.destroy', $media))
            ->assertUnprocessable();

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_user_can_delete_own_media(): void
    {
        Storage::fake('public');
        $user = $this->clientUser();
        $media = Media::factory()->create([
            'mediable_type' => User::class,
            'mediable_id' => $user->id,
            'disk' => 'public',
            'path' => 'media/test.jpg',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('client.media.destroy', $media))
            ->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_guest_cannot_access_media_library(): void
    {
        $this->get(route('client.media.index'))
            ->assertRedirect(route('login'));
    }
}
