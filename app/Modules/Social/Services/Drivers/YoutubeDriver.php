<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YoutubeDriver implements SocialNetworkInterface
{
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const THUMBNAIL_URL = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    private const PLAYLIST_ITEMS_URL = 'https://www.googleapis.com/youtube/v3/playlistItems';

    private const MAX_VIDEO_BYTES = 512 * 1024 * 1024;

    private const MAX_THUMBNAIL_BYTES = 2 * 1024 * 1024;

    private array $warnings = [];

    public function network(): string
    {
        return 'youtube';
    }

    /**
     * Block non-HTTPS schemes and RFC-1918 / link-local addresses to prevent SSRF.
     */
    private function assertSafeVideoUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? '') !== 'https') {
            throw new \RuntimeException('Video URL must use HTTPS.');
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '' || $host === 'localhost') {
            throw new \RuntimeException('Video URL points to a disallowed host.');
        }

        // Resolve to IP and block private/link-local ranges.
        $ip = gethostbyname($host);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException('Video URL resolves to a disallowed network address.');
        }
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        if (! $response->successful()) {
            throw new \RuntimeException('YouTube channel lookup failed (HTTP '.$response->status().'): '.$response->body());
        }

        $res = $response->json();
        $channel = $res['items'][0]['snippet'] ?? [];

        if (empty($res['items'][0]['id'])) {
            throw new \RuntimeException('YouTube returned no channel for this account.');
        }

        return [
            'account_id' => $res['items'][0]['id'] ?? '',
            'name' => $channel['title'] ?? '',
            'picture_url' => $channel['thumbnails']['default']['url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $this->warnings = [];
        $videoPath = $postData['video_path'] ?? null;
        $videoUrl = $postData['media_urls'][0] ?? null;
        $temporaryDownload = false;
        $options = $postData['youtube_options'] ?? [];

        if (! $videoPath && ! $videoUrl) {
            throw new \RuntimeException('YouTube publish requires a video_path or media_urls[0].');
        }

        // Download to temp if URL given — validate to prevent SSRF.
        if (! $videoPath && $videoUrl) {
            $this->assertSafeVideoUrl($videoUrl);
            $probe = Http::withoutRedirecting()->timeout(30)->head($videoUrl);
            if ($probe->successful() && (int) ($probe->header('Content-Length') ?? 0) > self::MAX_VIDEO_BYTES) {
                throw new \RuntimeException('Video exceeds the 512 MB upload limit.');
            }
            $videoPath = tempnam(sys_get_temp_dir(), 'yt_');
            if ($videoPath === false) {
                throw new \RuntimeException('Could not create a temporary file for the video download.');
            }
            $temporaryDownload = true;
            try {
                $download = Http::withoutRedirecting()->timeout(600)
                    ->sink($videoPath)
                    ->get($videoUrl);
                if ($download->redirect()) {
                    throw new \RuntimeException('Video URL redirects are not allowed. Use the final direct HTTPS file URL.');
                }
                if (! $download->successful()) {
                    throw new \RuntimeException('Video download returned HTTP '.$download->status().'.');
                }
                if ((int) ($download->header('Content-Length') ?? 0) > self::MAX_VIDEO_BYTES
                    || (int) filesize($videoPath) > self::MAX_VIDEO_BYTES) {
                    throw new \RuntimeException('Video exceeds the 512 MB upload limit.');
                }
            } catch (\Throwable $e) {
                @unlink($videoPath);
                throw new \RuntimeException('Failed to download video: '.$e->getMessage());
            }
        }

        if (! is_file($videoPath) || ! is_readable($videoPath)) {
            throw new \RuntimeException('The video file is unavailable or unreadable.');
        }

        $size = filesize($videoPath);
        if ($size === false || $size === 0) {
            throw new \RuntimeException('The video file is empty.');
        }
        if ($size > self::MAX_VIDEO_BYTES) {
            throw new \RuntimeException('Video exceeds Cerqle\'s 512 MB YouTube upload limit.');
        }

        $mimeType = mime_content_type($videoPath) ?: 'video/mp4';
        if (! str_starts_with($mimeType, 'video/') && $mimeType !== 'application/octet-stream') {
            throw new \RuntimeException('The supplied URL did not return a valid video file.');
        }

        $metadata = [
            'snippet' => [
                'title' => trim((string) ($postData['title'] ?? '')),
                'description' => $postData['description'] ?? ($postData['body'] ?? ''),
                'tags' => array_values($options['tags'] ?? []),
                'categoryId' => (string) ($options['category_id'] ?? '22'),
            ],
            'status' => [
                'privacyStatus' => $options['privacy_status'] ?? 'private',
                'selfDeclaredMadeForKids' => (bool) ($options['made_for_kids'] ?? false),
                'containsSyntheticMedia' => (bool) ($options['contains_synthetic_media'] ?? false),
            ],
        ];

        if (! empty($options['default_language'])) {
            $metadata['snippet']['defaultLanguage'] = $options['default_language'];
        }

        try {
            // 1. Initiate resumable upload
            $initResp = Http::withToken($account->access_token)
                ->withHeaders([
                    'X-Upload-Content-Type' => $mimeType,
                    'X-Upload-Content-Length' => $size,
                ])
                ->timeout(60)
                ->post(self::UPLOAD_URL.'?'.http_build_query([
                    'uploadType' => 'resumable',
                    'part' => 'snippet,status',
                    'notifySubscribers' => ($options['notify_subscribers'] ?? true) ? 'true' : 'false',
                ]), $metadata);

            if (! $initResp->successful()) {
                throw new \RuntimeException('YouTube upload initialization failed (HTTP '.$initResp->status().'): '.$this->providerError($initResp->json(), $initResp->body()));
            }

            $uploadUri = $initResp->header('Location');
            if (! $uploadUri) {
                throw new \RuntimeException('YouTube upload init returned no Location header.');
            }

            // 2. Stream the file body instead of loading it all into RAM.
            $stream = fopen($videoPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Could not open video file for streaming.');
            }

            $uploadResp = Http::withToken($account->access_token)
                ->withHeaders(['Content-Type' => $mimeType, 'Content-Length' => $size])
                ->timeout(1200)
                ->withBody($stream, $mimeType)
                ->put($uploadUri);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if (! $uploadResp->successful()) {
                throw new \RuntimeException('YouTube video upload failed (HTTP '.$uploadResp->status().'): '.$this->providerError($uploadResp->json(), $uploadResp->body()));
            }

            $videoId = $uploadResp->json('id', '');
            if ($videoId === '') {
                throw new \RuntimeException('YouTube uploaded the video but returned no video ID.');
            }

            // These operations happen after the video exists. They are best
            // effort so a transient thumbnail/playlist error cannot cause a
            // retry to upload a duplicate copy of the video.
            if (! empty($options['thumbnail_url'])) {
                $this->runOptionalStep('Custom thumbnail', fn () => $this->setThumbnail(
                    $account->access_token,
                    $videoId,
                    $options['thumbnail_url']
                ));
            }

            if (! empty($options['playlist_id'])) {
                $this->runOptionalStep('Playlist placement', fn () => $this->addToPlaylist(
                    $account->access_token,
                    $videoId,
                    $options['playlist_id']
                ));
            }

            Log::info('YouTube video uploaded', ['video_id' => $videoId, 'account_id' => $account->id]);

            return $videoId;
        } finally {
            // Never delete a caller-owned local file; only clean up the file this
            // driver downloaded into the system temp directory.
            if ($temporaryDownload && isset($videoPath) && file_exists($videoPath)) {
                @unlink($videoPath);
            }
        }
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    private function runOptionalStep(string $label, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            $message = $label.' failed: '.$e->getMessage();
            $this->warnings[] = $message;
            Log::warning('YouTube optional publishing step failed', ['step' => $label, 'error' => $e->getMessage()]);
        }
    }

    private function setThumbnail(string $accessToken, string $videoId, string $thumbnailUrl): void
    {
        $this->assertSafeVideoUrl($thumbnailUrl);
        $path = tempnam(sys_get_temp_dir(), 'yt_thumb_');
        if ($path === false) {
            throw new \RuntimeException('could not create a temporary file for the thumbnail.');
        }

        try {
            $download = Http::withoutRedirecting()->timeout(60)->sink($path)->get($thumbnailUrl);
            if ($download->redirect()) {
                throw new \RuntimeException('thumbnail URL redirects are not allowed; use the final direct HTTPS image URL.');
            }
            if (! $download->successful()) {
                throw new \RuntimeException('download returned HTTP '.$download->status().'.');
            }

            $size = filesize($path);
            $mime = mime_content_type($path) ?: '';
            if ($size === false || $size === 0 || $size > self::MAX_THUMBNAIL_BYTES) {
                throw new \RuntimeException('thumbnail must be a non-empty JPEG or PNG no larger than 2 MB.');
            }
            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw new \RuntimeException('thumbnail must be a JPEG or PNG image.');
            }

            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('could not open thumbnail for upload.');
            }

            try {
                $response = Http::withToken($accessToken)
                    ->timeout(120)
                    ->withBody($stream, $mime)
                    ->post(self::THUMBNAIL_URL.'?'.http_build_query(['videoId' => $videoId]));
            } finally {
                fclose($stream);
            }

            if (! $response->successful()) {
                throw new \RuntimeException('YouTube returned HTTP '.$response->status().': '.$this->providerError($response->json(), $response->body()));
            }
        } finally {
            @unlink($path);
        }
    }

    private function addToPlaylist(string $accessToken, string $videoId, string $playlistId): void
    {
        $response = Http::withToken($accessToken)->timeout(30)->post(self::PLAYLIST_ITEMS_URL.'?part=snippet', [
            'snippet' => [
                'playlistId' => $playlistId,
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $videoId],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('YouTube returned HTTP '.$response->status().': '.$this->providerError($response->json(), $response->body()));
        }
    }

    private function providerError(?array $json, string $fallback): string
    {
        return (string) data_get($json, 'error.message', mb_substr($fallback, 0, 300));
    }
}
