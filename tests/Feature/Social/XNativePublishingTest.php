<?php

namespace Tests\Feature\Social;

use App\Models\Media;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Modules\Social\Services\XMediaUploader;
use App\Modules\Social\Services\XProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class XNativePublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_and_native_create_do_not_insert_urls(): void
    {
        Http::fakeSequence()->push(['data' => ['id' => '12', 'name' => 'Name', 'username' => 'handle', 'profile_image_url' => 'https://example.test/p.jpg']])->push(['data' => ['id' => '99']]);
        $driver = new XDriver;
        $this->assertSame('handle', $driver->fetchAccountInfo('token')['username']);
        $account = new SocialAccount(['access_token' => 'token']);
        $this->assertSame('99', $driver->publish($account, ['body' => 'Hello', 'media_urls' => ['https://untrusted.test/file'], 'x_media_ids' => ['55']]));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.x.com/2/tweets' && $request->data() === ['text' => 'Hello', 'media' => ['media_ids' => ['55']]]);
        Http::assertSentCount(2);
    }

    public function test_create_error_outcomes_are_sanitized_and_not_retried(): void
    {
        $account = new SocialAccount(['access_token' => 'token']);
        foreach ([[401, 'reconnect', 'definite'], [402, 'credit', 'definite'], [403, 'credit', 'definite'], [429, 'rate_limit', 'definite'], [503, 'unknown', 'unknown'], [201, 'unknown', 'unknown']] as [$status, $category, $outcome]) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['detail' => 'credit secret-token'], $status, ['x-rate-limit-reset' => time() + 99999])]);
            try {
                (new XDriver)->publish($account, ['body' => 'Hello']);
                $this->fail('Expected exception');
            } catch (XProviderException $error) {
                $this->assertSame($category, $error->category);
                $this->assertSame($outcome, $error->outcome);
                $this->assertStringNotContainsString('secret-token', $error->getMessage());
                if ($status === 429) {
                    $this->assertSame(86400, $error->retryAfter);
                }
            }
            Http::assertSentCount(1);
        }
    }

    public function test_connection_failure_is_unknown(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        try {
            (new XDriver)->publish(new SocialAccount(['access_token' => 'token']), ['body' => 'Hello']);
            $this->fail('Expected exception');
        } catch (XProviderException $error) {
            $this->assertSame('unknown', $error->outcome);
        }
    }

    public function test_upload_advances_one_stage_processing_and_expiry(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = new SocialAccount(['workspace_id' => $workspace->id, 'access_token' => 'token']);
        $account->id = 123;
        $media = Media::factory()->create(['mediable_id' => $user->id, 'size_bytes' => 5]);
        Storage::disk('public')->put($media->path, 'hello');
        $post = SocialPost::create(['workspace_id' => $workspace->id, 'created_by' => $user->id, 'body' => 'Hello', 'status' => 'draft']);
        $post->media()->attach($media);
        Http::fakeSequence()->push(['data' => ['id' => '55', 'expires_after_secs' => 3600]])
            ->push([], 204)->push(['data' => ['processing_info' => ['state' => 'pending', 'check_after_secs' => 5]]])
            ->push(['data' => ['processing_info' => ['state' => 'succeeded']]])
            ->push(['data' => ['id' => '66', 'expires_after_secs' => 3600]]);
        $uploader = new XMediaUploader;
        $state = $uploader->advance($account, [$media->id], []);
        $this->assertSame('append', $state['uploads'][$media->id]['stage']);
        Http::assertSentCount(1);
        $state = $uploader->advance($account, [$media->id], $state);
        $this->assertSame('finalize', $state['uploads'][$media->id]['stage']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/55/append') && $request->isMultipart() && str_contains($request->body(), 'hello'));
        $state = $uploader->advance($account, [$media->id], $state);
        $this->assertSame('processing', $state['stage']);
        $this->assertSame([], $state['media_ids']);
        $state = $uploader->advance($account, [$media->id], $state);
        Http::assertSentCount(3);
        $this->travel(6)->seconds();
        $state = $uploader->advance($account, [$media->id], $state);
        $this->assertSame(['55'], $state['media_ids']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/2/media/upload?media_id=55'));
        $this->travel(3600)->seconds();
        $state = $uploader->advance($account, [$media->id], $state);
        $this->assertSame([], $state['media_ids']);
        $this->assertSame('66', $state['uploads'][$media->id]['id']);
        Http::assertSentCount(5);
    }

    public function test_unlinked_media_is_rejected_without_network_access(): void
    {
        Http::fake();
        $media = Media::factory()->create();
        $this->expectException(XProviderException::class);
        try {
            (new XMediaUploader)->advance(new SocialAccount(['workspace_id' => 999]), [$media->id], []);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_multiple_chunks_preserve_offset_and_refreshed_token(): void
    {
        Storage::fake('public');
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = new SocialAccount(['workspace_id' => $workspace->id, 'access_token' => 'first']);
        $media = Media::factory()->create(['mediable_id' => $user->id, 'size_bytes' => 4 * 1024 * 1024 + 4]);
        Storage::disk('public')->put($media->path, str_repeat('a', 4 * 1024 * 1024).'tail');
        $post = SocialPost::create(['workspace_id' => $workspace->id, 'body' => 'Hello', 'status' => 'draft']);
        $post->media()->attach($media);
        Http::fakeSequence()->push(['data' => ['id' => '77', 'expires_after_secs' => 3600]])->push([], 204)->push([], 204)->push(['data' => ['expires_after_secs' => 3600]]);
        $uploader = new XMediaUploader;
        $state = $uploader->advance($account, [$media->id], []);
        $state = $uploader->advance($account, [$media->id], $state);
        $this->assertSame(4 * 1024 * 1024, $state['uploads'][$media->id]['offset']);
        $this->assertSame('append', $state['uploads'][$media->id]['stage']);
        $account->access_token = 'refreshed';
        $state = $uploader->advance($account, [$media->id], $state);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/77/append') && $request->hasHeader('Authorization', 'Bearer refreshed') && str_contains($request->body(), 'tail'));
        $this->assertSame(2, $state['uploads'][$media->id]['segment_index']);
        $state = $uploader->advance($account, [$media->id], $state);
        $this->assertSame('ready', $state['stage']);
        $this->assertSame(['77'], $state['media_ids']);
        Http::assertSentCount(4);
    }

    public function test_permission_and_actual_rate_reset_contract(): void
    {
        $error = XProviderException::fromResponse(new Response(Http::response(['detail' => 'secret'], 403)->wait()));
        $this->assertSame('permission', $error->category);
        // X's real out-of-credits answer is 402 with a body that names no
        // credit keyword the 403 check looks for; it must still read as credits.
        $error = XProviderException::fromResponse(new Response(Http::response(['title' => 'CreditsDepleted', 'type' => 'https://api.twitter.com/2/problems/credits'], 402)->wait()));
        $this->assertSame('credit', $error->category);
        $this->assertSame('X API credits are unavailable. Contact your administrator.', $error->getMessage());
        $this->assertStringNotContainsString('secret', $error->getMessage());
        $error = XProviderException::fromResponse(new Response(Http::response([], 429, ['Retry-After' => '10', 'x-rate-limit-reset' => time() + 1800])->wait()));
        $this->assertGreaterThanOrEqual(1799, $error->retryAfter);
        $this->assertLessThanOrEqual(1800, $error->retryAfter);
    }
}
