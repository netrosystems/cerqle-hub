<?php

namespace Tests\Unit\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YoutubeDriverTest extends TestCase
{
    public function test_it_uploads_video_with_youtube_metadata(): void
    {
        $videoPath = tempnam(sys_get_temp_dir(), 'cerqle_youtube_test_');
        $this->writeMinimalMp4($videoPath);

        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://youtube-upload.test/session/123',
            ]),
            'youtube-upload.test/session/123' => Http::response(['id' => 'video-123'], 200),
        ]);

        $account = new SocialAccount(['access_token' => 'token']);
        $driver = new YoutubeDriver;

        try {
            $videoId = $driver->publish($account, [
                'video_path' => $videoPath,
                'title' => 'Cerqle upload test',
                'body' => 'Uploaded automatically from Cerqle.',
                'youtube_options' => [
                    'privacy_status' => 'unlisted',
                    'category_id' => 27,
                    'tags' => ['cerqle', 'automation'],
                    'made_for_kids' => false,
                    'contains_synthetic_media' => true,
                    'notify_subscribers' => false,
                ],
            ]);
        } finally {
            @unlink($videoPath);
        }

        $this->assertSame('video-123', $videoId);
        $this->assertSame([], $driver->warnings());
        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), '/upload/youtube/v3/videos')) {
                return false;
            }

            return str_contains($request->url(), 'notifySubscribers=false')
                && data_get($request->data(), 'snippet.title') === 'Cerqle upload test'
                && data_get($request->data(), 'snippet.categoryId') === '27'
                && data_get($request->data(), 'status.privacyStatus') === 'unlisted'
                && data_get($request->data(), 'status.containsSyntheticMedia') === true;
        });
    }

    public function test_optional_thumbnail_failure_does_not_duplicate_a_successful_video_upload(): void
    {
        $videoPath = tempnam(sys_get_temp_dir(), 'cerqle_youtube_test_');
        $this->writeMinimalMp4($videoPath);

        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
                'Location' => 'https://youtube-upload.test/session/456',
            ]),
            'youtube-upload.test/session/456' => Http::response(['id' => 'video-456'], 200),
            'cdn.example.com/thumbnail.jpg' => Http::response('', 404),
        ]);

        $driver = new YoutubeDriver;

        try {
            $videoId = $driver->publish(new SocialAccount(['access_token' => 'token']), [
                'video_path' => $videoPath,
                'title' => 'Successful core upload',
                'body' => '',
                'youtube_options' => ['thumbnail_url' => 'https://cdn.example.com/thumbnail.jpg'],
            ]);
        } finally {
            @unlink($videoPath);
        }

        $this->assertSame('video-456', $videoId);
        $this->assertCount(1, $driver->warnings());
        $this->assertStringContainsString('Custom thumbnail failed', $driver->warnings()[0]);
    }

    private function writeMinimalMp4(string $path): void
    {
        file_put_contents($path, "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2");
    }
}
