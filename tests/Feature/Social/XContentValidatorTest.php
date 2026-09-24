<?php

namespace Tests\Feature\Social;

use App\Models\Media;
use App\Models\User;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialMediaLifecycleService;
use App\Modules\Social\Services\XContentValidator;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class XContentValidatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_immediate_browser_store_persists_publishing_before_queue_dispatch(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $account = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => 'dispatch-browser', 'name' => 'X', 'access_token' => 'test', 'active' => true]);
        $queue = $this->observePublishingStatusAtDispatch();
        $this->actingAs($user)->postJson(route('client.social.posts.store'), [
            'target_accounts' => [$account->id], 'body' => 'Immediate browser post',
        ])->assertSuccessful();
        $this->assertSame(['publishing'], $queue->statuses);
    }

    public function test_immediate_api_store_persists_publishing_before_queue_dispatch(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('dispatch-test', [ApiAbilities::SOCIAL_WRITE])->plainTextToken;
        $account = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => 'dispatch-api', 'name' => 'X', 'access_token' => 'test', 'active' => true]);
        $queue = $this->observePublishingStatusAtDispatch();
        $this->withToken($token)->postJson('/api/v1/social/posts', [
            'account_ids' => [$account->id], 'body' => 'Immediate API post',
        ])->assertCreated();
        $this->assertSame(['publishing'], $queue->statuses);
    }

    private function observePublishingStatusAtDispatch(): QueueFake
    {
        $queue = new class($this->app) extends QueueFake
        {
            /** @var list<string> */
            public array $statuses = [];

            public function push($job, $data = '', $queue = null)
            {
                if ($job instanceof PublishSocialPostJob) {
                    $this->statuses[] = SocialPost::findOrFail($job->postId)->status;
                }

                return parent::push($job, $data, $queue);
            }
        };
        Queue::swap($queue);

        return $queue;
    }

    public function test_weighted_unicode_emoji_and_normalization_boundaries(): void
    {
        $validator = new XContentValidator;
        foreach (['a' => 1, '界' => 2, '👨‍👩‍👧‍👦' => 2, '🙋🏽' => 2, '🇧🇩' => 2, '1️⃣' => 2, '©' => 1, '©️' => 2, "e\u{0301}" => 1] as $text => $weight) {
            $this->assertSame($weight, $validator->weightedLength($text), $text);
        }
        foreach (['a', '界', '👨‍👩‍👧‍👦', '🙋🏽'] as $text) {
            $count = 280 / $validator->weightedLength($text);
            $this->assertSame([], $validator->textErrors(str_repeat($text, $count)));
            $this->assertArrayHasKey('body', $validator->textErrors(str_repeat($text, $count).'a'));
        }
        $this->assertArrayHasKey('body', $validator->textErrors("bad\xFF"));
    }

    public function test_links_including_scheme_less_and_unicode_are_blocked(): void
    {
        $validator = new XContentValidator;
        foreach (['https://example.com', 'example.com/path', 'www.example.com', '例子.中国', 'ftp://host', 'user@example.org', 'https://127.0.0.1'] as $link) {
            $this->assertArrayHasKey('body', $validator->textErrors('See '.$link), $link);
        }
        $this->assertSame([], $validator->textErrors('Hello @someone #news. This is fine!'));
    }

    public function test_effective_override_keeps_missing_base_fields_and_ignores_disabled_customization(): void
    {
        $validator = new XContentValidator;
        $payload = ['body' => 'base', 'media_ids' => [1], 'platform_payloads' => ['twitter' => ['customize' => true, 'body' => 'custom']]];
        $this->assertSame('custom', $validator->effectivePayload($payload)['body']);
        $this->assertSame([1], $validator->effectivePayload($payload)['media_ids']);
        $payload['platform_payloads']['twitter']['customize'] = false;
        $this->assertSame('base', $validator->effectivePayload($payload)['body']);
    }

    public function test_actual_images_and_size_type_count_ownership_and_external_urls(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Storage::fake('local');
        $image = $this->image($user);
        $validator = new XContentValidator;
        $validator->assertPayload(['media_ids' => [$image->id], 'media_urls' => [$image->url()]], $workspace->id);
        $cases = [
            ['media_ids' => [$image->id], 'media_urls' => ['https://evil.example/image.png']],
            ['media_ids' => [$image->id, $image->id]],
            ['media_ids' => [999999]],
            ['media_ids' => [$this->image(User::factory()->create())->id]],
            ['media_ids' => [$this->image($user, ['size_bytes' => 5 * 1024 * 1024 + 1])->id]],
            // A PNG labelled as a GIF: the real file type must match.
            ['media_ids' => [$this->image($user, ['mime_type' => 'image/gif'])->id]],
            // Cerqle caps X at three images, below X's own four.
            ['media_ids' => array_map(fn () => $this->image($user)->id, range(1, 4))],
        ];
        foreach ($cases as $payload) {
            $this->assertRejected($validator, $payload, $workspace->id);
        }
        $validator->assertPayload(['media_ids' => array_map(fn () => $this->image($user)->id, range(1, XContentValidator::MAX_IMAGES))], $workspace->id);
        $validator->assertPayload(['body' => str_repeat('界', 141), 'status' => 'draft'], $workspace->id);
        $this->assertRejected($validator, ['media_ids' => [999999], 'status' => 'draft'], $workspace->id);
        $image->update(['size_bytes' => 5 * 1024 * 1024]);
        $validator->assertPayload(['media_ids' => [$image->id]], $workspace->id);
        Storage::disk('local')->put($image->path, 'not an image');
        $this->assertRejected($validator, ['media_ids' => [$image->id]], $workspace->id);
        Storage::disk('local')->delete($image->path);
        $this->assertRejected($validator, ['media_ids' => [$image->id]], $workspace->id);
    }

    public function test_video_metadata_fails_closed_and_enforces_codec_duration_container(): void
    {
        $validator = new XContentValidator;
        $valid = ['format' => ['duration' => '140', 'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'], 'streams' => [['codec_type' => 'video', 'codec_name' => 'h264']]];
        $this->assertTrue($validator->validVideoMetadata($valid));
        $audio = ['codec_type' => 'audio', 'codec_name' => 'aac'];
        $valid['streams'][] = $audio;
        $this->assertTrue($validator->validVideoMetadata($valid));
        foreach ([[], array_replace_recursive($valid, ['format' => ['duration' => 140.01]]), array_replace_recursive($valid, ['format' => ['duration' => 'NaN']]), array_replace_recursive($valid, ['format' => ['format_name' => 'matroska']]), array_replace_recursive($valid, ['streams' => [0 => ['codec_name' => 'hevc']]]), array_replace_recursive($valid, ['streams' => [1 => ['codec_name' => 'mp3']]]), ['format' => $valid['format'], 'streams' => [$audio]]] as $metadata) {
            $this->assertFalse($validator->validVideoMetadata($metadata));
        }
    }

    public function test_spoofed_video_metadata_and_unavailable_probe_are_rejected(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Storage::fake('local');
        $media = $this->image($user, ['mime_type' => 'video/mp4', 'meta' => ['duration' => 1, 'codec_name' => 'h264']]);
        $this->assertRejected(new XContentValidator, ['media_ids' => [$media->id]], $workspace->id);
        $original = getenv('PATH');
        try {
            putenv('PATH=/nonexistent-x-probe');
            // A real MP4 signature passes MIME detection but cannot be probed.
            Storage::disk('local')->put($media->path, pack('N', 24).'ftypisom'.pack('N', 0).'isommp42'.str_repeat("\0", 64));
            $this->assertRejected(new XContentValidator, ['media_ids' => [$media->id]], $workspace->id);
        } finally {
            putenv('PATH='.$original);
        }
    }

    public function test_real_h264_mp4_is_probed_when_ffmpeg_is_available(): void
    {
        $probe = new Process(['ffmpeg', '-version']);
        try {
            $probe->run();
        } catch (\Throwable) {
            $this->markTestSkipped('ffmpeg unavailable; fail-closed test still runs.');
        }
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('ffmpeg unavailable.');
        }
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Storage::fake('local');
        $media = $this->image($user, ['mime_type' => 'video/mp4']);
        $path = Storage::disk('local')->path($media->path);
        $process = new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=320x240:d=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-f', 'mp4', $path]);
        $process->mustRun();
        $media->update(['size_bytes' => filesize($path)]);
        (new XContentValidator)->assertPayload(['media_ids' => [$media->id]], $workspace->id);
        $media->update(['size_bytes' => 500 * 1024 * 1024]);
        (new XContentValidator)->assertPayload(['media_ids' => [$media->id]], $workspace->id);
        $this->assertRejected(new XContentValidator, ['media_ids' => [$media->id, $this->image($user)->id]], $workspace->id);
        $media->update(['size_bytes' => 500 * 1024 * 1024 + 1]);
        $this->assertRejected(new XContentValidator, ['media_ids' => [$media->id]], $workspace->id);
    }

    public function test_browser_store_api_and_publish_now_enforce_but_draft_update_allows_incomplete(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $account = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => 'x-test', 'name' => 'X', 'access_token' => 'test', 'active' => true]);
        $payload = ['target_accounts' => [$account->id], 'body' => str_repeat('界', 141)];
        $this->actingAs($user)->postJson(route('client.social.posts.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('body');
        $post = SocialPost::create(['workspace_id' => $workspace->id, 'target_accounts' => [$account->id], 'body' => '', 'status' => 'draft']);
        $this->actingAs($user)->put(route('client.social.posts.update', $post), ['target_accounts' => [$account->id], 'body' => ''])->assertSessionHasNoErrors();
        $this->actingAs($user)->postJson(route('client.social.posts.publish-now', $post))->assertUnprocessable();
        $this->actingAs($user)->putJson(route('client.social.posts.update', $post), array_merge($payload, ['scheduled_at' => now()->addHour()->toIso8601String()]))->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_api_enforces_x_validation_with_existing_token_and_addon_guards(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $account = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => 'x-api', 'name' => 'X', 'access_token' => 'test', 'active' => true]);
        $this->grantDeveloperToolsAddon($user);
        $token = $user->createToken('x-test', [ApiAbilities::SOCIAL_WRITE])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/social/posts', ['account_ids' => [$account->id], 'body' => 'example.com'])->assertUnprocessable()->assertJsonValidationErrors('body');
        Queue::assertNothingPushed();
        $this->withToken($token)->postJson('/api/v1/social/posts', ['account_ids' => [$account->id], 'body' => str_repeat('界', 140)])->assertCreated();
        Queue::assertPushed(PublishSocialPostJob::class);
    }

    public function test_permanent_library_ids_are_persisted_and_never_released_or_purged(): void
    {
        Queue::fake();
        Storage::fake('local', ['url' => 'https://uploads.example.test']);
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        $account = SocialAccount::create(['workspace_id' => $workspace->id, 'network' => 'twitter', 'account_id' => 'library', 'name' => 'X', 'access_token' => 'test', 'active' => true]);
        $media = $this->image($user, ['is_temporary' => false, 'collection' => 'default']);
        $this->actingAs($user)->postJson(route('client.social.posts.store'), [
            'target_accounts' => [$account->id], 'body' => '', 'media_ids' => [$media->id], 'media_urls' => [$media->url()],
        ])->assertSuccessful();
        $post = SocialPost::firstOrFail();
        $this->assertSame([$media->id], data_get($post->platform_payloads, 'twitter.media_ids'));
        $this->assertTrue($post->media()->where('media.id', $media->id)->exists());
        $lifecycle = app(SocialMediaLifecycleService::class);
        $post->update(['status' => 'published']);
        $lifecycle->releaseAfterSuccessfulPublish($post);
        $lifecycle->detachDeletedPost($post);
        $media->refresh();
        $this->assertNull($media->quota_released_at);
        $this->assertNull($media->purge_after);
        $this->assertNull($post->fresh()->temporary_media_released_at);
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_one_gif_goes_alone_and_within_fifteen_megabytes(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Storage::fake('local');
        $validator = new XContentValidator;
        $gif = $this->image($user, ['mime_type' => 'image/gif'], 'loop.gif');

        $validator->assertPayload(['media_ids' => [$gif->id]], $workspace->id);

        $this->assertRejected($validator, ['media_ids' => [$gif->id, $this->image($user)->id]], $workspace->id);
        $this->assertRejected($validator, ['media_ids' => [$gif->id, $this->image($user, ['mime_type' => 'image/gif'], 'second.gif')->id]], $workspace->id);
        $this->assertRejected($validator, ['media_ids' => [$this->image($user, ['mime_type' => 'image/gif', 'size_bytes' => 15 * 1024 * 1024 + 1], 'big.gif')->id]], $workspace->id);
        // A video is the same kind of single attachment as a GIF.
        $this->assertRejected($validator, ['media_ids' => [$this->image($user, ['mime_type' => 'video/mp4'])->id, $this->image($user)->id]], $workspace->id);
    }

    public function test_the_cap_messages_say_what_to_change(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createSubscribedWorkspaceContext();
        Storage::fake('local');
        $validator = new XContentValidator;
        try {
            $validator->assertPayload(['media_ids' => array_map(fn () => $this->image($user)->id, range(1, 4))], $workspace->id);
            $this->fail('Expected four images to be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['X posts can have up to 3 images.'], $e->errors()['media_ids']);
        }
        try {
            $validator->assertPayload(['media_ids' => [$this->image($user, ['mime_type' => 'image/gif'], 'a.gif')->id, $this->image($user)->id]], $workspace->id);
            $this->fail('Expected a GIF with an image to be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['X posts can have one video or GIF on its own. Remove the other attachments.'], $e->errors()['media_ids']);
        }
    }

    private function image(User $user, array $attributes = [], string $name = 'image.png'): Media
    {
        $file = UploadedFile::fake()->image($name);
        $path = Storage::disk('local')->putFile('x-tests', $file);

        return Media::create(array_merge(['mediable_type' => User::class, 'mediable_id' => $user->id, 'disk' => 'local', 'path' => $path, 'filename' => 'image.png', 'mime_type' => 'image/png', 'size_bytes' => $file->getSize(), 'collection' => 'social', 'is_temporary' => true], $attributes));
    }

    private function assertRejected(XContentValidator $validator, array $payload, int $workspaceId): void
    {
        try {
            $validator->assertPayload($payload, $workspaceId);
            $this->fail('Expected X validation failure.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
