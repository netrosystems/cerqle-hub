<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\Media\AttachmentService;
use App\Services\PublicHttpClient;
use App\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MessageMediaResolver
{
    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly AttachmentService $attachmentService,
        private readonly PublicHttpClient $publicHttpClient,
    ) {}

    public function response(Message $message, Request $request): Response
    {
        $message->loadMissing('conversation.channelAccount');
        $payload = $message->payload ?? [];
        $type = (string) ($message->type ?? 'image');
        $path = $this->cachedPath($message, $payload);

        if ($path) {
            if ($this->isHeicPath($path)) {
                return $this->storeAndStream(
                    $message,
                    array_merge($payload, ['preview_url' => null]),
                    (string) $this->storageManager->disk()->get($path),
                    $payload['mime_type'] ?? $payload[$type]['mime_type'] ?? $this->mimeTypeFromPath($path),
                    $request,
                );
            }

            return $this->stream($path, $payload['mime_type'] ?? $payload[$type]['mime_type'] ?? null);
        }

        return in_array($message->channel, ['messenger', 'instagram'], true)
            ? $this->metaResponse($message, $payload, $type, $request)
            : $this->whatsappResponse($message, $payload, $type, $request);
    }

    /** @return array<string, mixed>|null */
    public function augmentPayload(Message $message, Request $request, ?string $routeName = null): ?array
    {
        $payload = $message->payload;
        if (! $payload) {
            return $payload;
        }

        $url = $this->routedUrl($message, $routeName);
        if ($url) {
            $type = (string) $message->type;
            $payload['media_url'] = $url;
            $payload['attachment_url'] = $url;
            $payload['preview_url'] = $url;
            $payload['url'] = $url;
            $payload['link'] = $url;
            if (isset($payload[$type]) && is_array($payload[$type])) {
                $payload[$type]['url'] = $url;
                $payload[$type]['preview_url'] = $url;
                $payload[$type]['link'] = $url;
            }
        } elseif (! empty($payload['preview_url'])) {
            $payload['preview_url'] = $this->browserSafeUrl((string) $payload['preview_url'], $request);
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function whatsappResponse(Message $message, array $payload, string $type, Request $request): Response
    {
        $mediaId = $payload[$type]['id'] ?? $payload['media_id'] ?? null;
        abort_if(! $mediaId, 404, 'No media available.');

        $conversation = $message->conversation;
        $user = $request->user();
        $workspaceId = $user?->getAttribute('current_workspace_id')
            ?? $user?->getAttribute('workspace_id')
            ?? $conversation?->workspace_id;
        abort_if(! $workspaceId, 503, 'WhatsApp account not configured.');

        $phoneNumberId = $conversation?->channelAccount?->phone_number_id;
        $client = $phoneNumberId
            ? CloudApiClient::forPhoneNumber($phoneNumberId, (int) $workspaceId)
            : CloudApiClient::forWorkspace((int) $workspaceId);
        abort_if(! $client, 503, 'WhatsApp account not configured.');

        try {
            ['url' => $downloadUrl, 'mime_type' => $mimeType] = $client->getMediaUrl((string) $mediaId);

            return $this->storeAndStream($message, $payload, $client->downloadMedia($downloadUrl), $mimeType, $request);
        } catch (\Throwable $e) {
            abort(502, 'Could not retrieve this attachment. Please try again later.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function metaResponse(Message $message, array $payload, string $type, Request $request): Response
    {
        $url = $payload[$type]['url'] ?? $payload['_meta_attachment']['url'] ?? null;
        abort_if(($payload['_meta_attachment']['provider'] ?? null) !== 'meta' || ! is_string($url), 404, 'No media available.');
        abort_unless($this->isAllowedMetaUrl($url), 422, 'Unsupported Meta media URL.');

        try {
            $response = $this->publicHttpClient->send(
                Http::timeout(20),
                'GET',
                $url,
                followRedirects: true,
                maxBytes: 12 * 1024 * 1024,
            );
            abort_unless($response->successful(), 502, 'Could not retrieve this attachment.');
            $mimeType = (string) ($response->header('Content-Type') ?: ($payload['mime_type'] ?? 'application/octet-stream'));

            return $this->storeAndStream($message, $payload, $response->body(), $mimeType, $request);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            abort(502, 'Could not retrieve this attachment. Please try again later.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function storeAndStream(Message $message, array $payload, string $bytes, string $mimeType, Request $request): Response
    {
        [$bytes, $mimeType, $payload] = $this->convertHeic($bytes, $mimeType, $payload, (string) $message->type);
        $path = $this->storageManager->prefixedPath("message-media/{$message->id}.{$this->extensionFromMime($mimeType)}");
        abort_unless($this->storageManager->disk()->put($path, $bytes), 502, 'Media cache write failed.');

        $message->update(['payload' => array_merge($payload, [
            'path' => $path,
            'preview_url' => $this->browserSafeUrl($this->storageManager->disk()->url($path), $request),
            'mime_type' => $mimeType,
        ])]);

        return $this->stream($path, $mimeType);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0:string, 1:string, 2:array<string, mixed>}
     */
    private function convertHeic(string $bytes, string $mimeType, array $payload, string $type): array
    {
        if (! $this->isHeic($bytes, $mimeType, $payload, $type)) {
            return [$bytes, $mimeType, $payload];
        }

        $temp = tempnam(sys_get_temp_dir(), 'inbound_heic_');
        if (! $temp) {
            return [$bytes, $mimeType, $payload];
        }
        file_put_contents($temp, $bytes);

        try {
            $converted = $this->attachmentService->attemptHeicConversion($temp);
            if (! $converted || ! is_file($converted)) {
                return [$bytes, $mimeType, $payload];
            }
            $convertedBytes = (string) file_get_contents($converted);
            @unlink($converted);
            $payload['mime_type'] = 'image/jpeg';
            $payload['is_converted_heic'] = true;
            $payload['original_mime_type'] = $payload['original_mime_type'] ?? 'image/heic';
            if (isset($payload['filename']) && is_string($payload['filename'])) {
                $payload['filename'] = pathinfo($payload['filename'], PATHINFO_FILENAME).'.jpg';
            }
            if (isset($payload[$type]) && is_array($payload[$type])) {
                $payload[$type]['mime_type'] = 'image/jpeg';
                $payload[$type]['is_converted_heic'] = true;
            }

            return [$convertedBytes, 'image/jpeg', $payload];
        } finally {
            @unlink($temp);
        }
    }

    /** @param array<string, mixed> $payload */
    private function isHeic(string $bytes, string $mimeType, array $payload, string $type): bool
    {
        if (in_array(strtolower(trim(explode(';', $mimeType)[0])), ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            return true;
        }
        foreach ([$payload['filename'] ?? null, $payload[$type]['filename'] ?? null, $payload[$type]['url'] ?? null] as $candidate) {
            $path = is_string($candidate) ? parse_url($candidate, PHP_URL_PATH) : null;
            if (is_string($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['heic', 'heif', 'heics', 'heifs'], true)) {
                return true;
            }
        }

        return strlen($bytes) >= 12
            && substr($bytes, 4, 4) === 'ftyp'
            && in_array(strtolower(substr($bytes, 8, 4)), ['heic', 'heix', 'hevc', 'heim', 'heis', 'mif1', 'msf1'], true);
    }

    /** @param array<string, mixed> $payload */
    private function cachedPath(Message $message, array $payload): ?string
    {
        $disk = $this->storageManager->disk();
        $type = (string) $message->type;
        $path = $payload['path'] ?? $payload[$type]['path'] ?? null;
        if (is_string($path) && $path !== '' && $disk->exists($path)) {
            return $path;
        }

        $publicPath = $this->pathFromPublicUrl($payload['preview_url'] ?? $payload[$type]['preview_url'] ?? null);
        if ($publicPath && $disk->exists($publicPath)) {
            return $publicPath;
        }

        $prefix = $this->storageManager->prefixedPath("message-media/{$message->id}");

        return collect($disk->files($this->storageManager->prefixedPath('message-media')))
            ->first(fn ($file) => str_starts_with($file, $prefix));
    }

    private function pathFromPublicUrl(mixed $url): ?string
    {
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;
        if (! is_string($path)) {
            return null;
        }
        $position = strpos(rawurldecode($path), 'message-media/');

        return $position === false ? null : $this->storageManager->prefixedPath(substr(rawurldecode($path), $position));
    }

    private function routedUrl(Message $message, ?string $routeName): ?string
    {
        if (! $routeName || ! $this->hasMedia($message, $message->payload ?? [])) {
            return null;
        }
        $message->loadMissing('conversation');
        if (! $message->conversation) {
            return null;
        }
        $parameters = ['uuid' => $message->conversation->uuid, 'message' => $message->id];
        if ($routeName === 'api.v1.mobile.conversations.messages.media') {
            return route($routeName, $parameters);
        }

        return route($routeName, ['conversation' => $message->conversation->uuid, 'message' => $message->id]);
    }

    /** @param array<string, mixed> $payload */
    private function hasMedia(Message $message, array $payload): bool
    {
        $type = (string) $message->type;

        return in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true)
            && (! empty($payload['preview_url'])
                || ! empty($payload['path'])
                || ! empty($payload[$type]['path'])
                || ! empty($payload[$type]['id'])
                || ! empty($payload[$type]['url'])
                || ! empty($payload['media_id'])
                || ! empty($payload['_meta_attachment']['url']));
    }

    private function stream(string $path, ?string $mimeType): Response
    {
        $disk = $this->storageManager->disk();
        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), 404, 'Attachment unavailable.');

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mimeType ?: ($disk->mimeType($path) ?: 'application/octet-stream'),
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function isAllowedMetaUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && collect(['facebook.com', 'fbcdn.net', 'fbsbx.com', 'cdninstagram.com', 'instagram.com'])
                ->contains(fn ($suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));
    }

    private function isHeicPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['heic', 'heif', 'heics', 'heifs'], true);
    }

    private function mimeTypeFromPath(string $path): string
    {
        return str_ends_with(strtolower($path), '.heif') ? 'image/heif' : 'image/heic';
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match (strtolower(trim(explode(';', $mimeType)[0]))) {
            'image/jpeg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'image/heic', 'image/heic-sequence' => 'heic', 'image/heif', 'image/heif-sequence' => 'heif',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/aac' => 'm4a', 'audio/ogg', 'application/ogg' => 'ogg', 'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function browserSafeUrl(string $url, Request $request): string
    {
        if (! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return ($request->isSecure() || app()->environment('production')) && $host && strcasecmp($host, $request->getHost()) === 0
            ? 'https://'.substr($url, strlen('http://'))
            : $url;
    }
}
