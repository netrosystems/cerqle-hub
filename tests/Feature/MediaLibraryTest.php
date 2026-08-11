<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MediaService;
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
