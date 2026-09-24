<?php

namespace App\Modules\Social\Services;

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class XContentValidator
{
    /**
     * Cerqle's own caps, tighter than X allows (four images), because X bills
     * API use per request and each attachment is uploaded separately.
     */
    public const MAX_IMAGES = 3;

    public const MAX_MOTION = 1;

    public const IMAGE_TYPES = ['image/jpeg', 'image/png'];

    /** A video or an animated GIF; either one goes alone. */
    public const MOTION_TYPES = ['video/mp4', 'image/gif'];

    private const LIMIT_BYTES = ['image/jpeg' => 5, 'image/png' => 5, 'image/gif' => 15, 'video/mp4' => 500];

    /**
     * Resolve fields exactly as SocialPublisher::payloadFor does; media IDs follow media URLs.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function effectivePayload(array $payload): array
    {
        $override = (array) data_get($payload, 'platform_payloads.twitter', []);
        if ($override['customize'] ?? false) {
            foreach (['title', 'body', 'media_urls', 'media_ids'] as $field) {
                if (array_key_exists($field, $override)) {
                    $payload[$field] = $override[$field];
                }
            }
        }
        $payload['twitter_options'] = (array) ($override['options'] ?? $payload['twitter_options'] ?? []);

        return $payload;
    }

    /**
     * Validate one effective X payload. Runtime callers MUST pin status=publishing.
     * Only status=draft permits incomplete content.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertPayload(array $payload, int $workspaceId): void
    {
        $draft = ($payload['status'] ?? null) === 'draft';
        $errors = $draft ? [] : $this->textErrors((string) ($payload['body'] ?? ''));
        $ids = array_values(array_filter($payload['media_ids'] ?? [], fn ($id) => $id !== null && $id !== ''));
        $workspace = Workspace::find($workspaceId);
        if (! $workspace) {
            throw ValidationException::withMessages(['media_ids' => ['Invalid workspace.']]);
        }
        $query = Media::query()->whereIn('id', $ids)->where('mediable_type', User::class);
        if ($workspace->client_id) {
            $query->whereIn('mediable_id', User::where('client_id', $workspace->client_id)->select('id'));
        } else {
            $query->where('mediable_id', $workspace->owner_id);
        }
        $media = $query->get();
        if ($media->count() !== count($ids) || count(array_unique($ids)) !== count($ids)) {
            $errors['media_ids'] = ['Choose valid, authorized uploaded media IDs without duplicates.'];
        }
        // Composer URLs may accompany IDs, but never authorize an external attachment.
        $authorizedUrls = $media->map(fn (Media $item) => $item->url())->all();
        foreach (array_filter($payload['media_urls'] ?? []) as $url) {
            if (! in_array($url, $authorizedUrls, true)) {
                $errors['media_urls'] = ['X attachments must be authorized uploads, not external URLs.'];
            }
        }
        if (! $draft) {
            if (! empty(data_get($payload, 'twitter_options.link_url'))) {
                $errors['twitter_options.link_url'] = ['Links are not supported in X posts.'];
            }
            if (trim((string) ($payload['body'] ?? '')) === '' && $ids === []) {
                $errors['body'] = ['X requires text or compatible uploaded media.'];
            }
            $motion = $media->filter(fn (Media $item) => in_array($item->mime_type, self::MOTION_TYPES, true));
            if ($motion->isNotEmpty() && $media->count() > self::MAX_MOTION) {
                $errors['media_ids'] = ['X posts can have one video or GIF on its own. Remove the other attachments.'];
            } elseif ($media->count() > self::MAX_IMAGES) {
                $errors['media_ids'] = ['X posts can have up to '.self::MAX_IMAGES.' images.'];
            }
            if (! isset($errors['media_ids'])) {
                foreach ($media as $item) {
                    if (! $this->validMedia($item)) {
                        $errors['media_ids'] = ['X accepts JPEG/PNG images up to 5 MB, one GIF up to 15 MB, or one MP4 up to 500 MB and 140 seconds (H.264 video, optional AAC audio). Media must be readable and verifiable.'];
                        break;
                    }
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, array<int, string>> */
    public function textErrors(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8') || preg_match('/[\x{FFFE}\x{FEFF}\x{FFFF}]/u', $text)) {
            return ['body' => ['X content contains invalid Unicode.']];
        }
        $errors = [];
        // Deliberately block links, including scheme-less and internationalized domains.
        if (preg_match('~(?:[a-z][a-z0-9+.-]*://|www\.|(?<![\p{L}\p{N}_])[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?)*\.(?:[\p{L}]{2,63})(?![\p{L}\p{N}_]))~iu', $text)) {
            $errors['body'] = ['Links are not supported in X posts, including links without a scheme.'];
        }
        if ($this->weightedLength($text) > 280) {
            $errors['body'][] = 'X content cannot exceed 280 weighted characters.';
        }

        return $errors;
    }

    public function weightedLength(string $text): int
    {
        $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        preg_match_all('/\X/u', $text, $clusters);
        $length = 0;
        foreach ($clusters[0] as $cluster) {
            if (preg_match('/\p{Emoji_Presentation}|\p{Extended_Pictographic}\x{FE0F}|[0-9#*]\x{FE0F}?\x{20E3}/u', $cluster)) {
                $length += 2;

                continue;
            }
            foreach (mb_str_split($cluster) as $character) {
                $code = mb_ord($character);
                $length += ($code <= 0x10FF || ($code >= 0x2000 && $code <= 0x200D)
                    || ($code >= 0x2010 && $code <= 0x201F) || ($code >= 0x2032 && $code <= 0x2037)) ? 1 : 2;
            }
        }

        return $length;
    }

    private function validMedia(Media $media): bool
    {
        $video = $media->mime_type === 'video/mp4';
        $limit = (self::LIMIT_BYTES[$media->mime_type] ?? 0) * 1024 * 1024;
        if ($limit === 0 || $media->size_bytes <= 0 || $media->size_bytes > $limit) {
            return false;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'x-media-');
        if ($temporary === false) {
            return false;
        }
        $source = null;
        $destination = null;
        try {
            $source = Storage::disk($media->disk)->readStream($media->path);
            $destination = fopen($temporary, 'wb');
            if (! is_resource($source) || ! is_resource($destination)) {
                return false;
            }
            $bytes = stream_copy_to_stream($source, $destination, $limit + 1);
            fclose($destination);
            $destination = null;
            if (! $bytes || $bytes > $limit) {
                return false;
            }
            if (! $video) {
                $image = @getimagesize($temporary);

                return $image !== false && $image['mime'] === $media->mime_type;
            }
            if ((new \finfo(FILEINFO_MIME_TYPE))->file($temporary) !== 'video/mp4') {
                return false;
            }
            $process = new Process(['ffprobe', '-v', 'error', '-protocol_whitelist', 'file', '-show_format', '-show_streams', '-of', 'json', $temporary]);
            $process->setTimeout(20);
            $process->run();
            if (! $process->isSuccessful()) {
                return false;
            }
            $metadata = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            return $this->validVideoMetadata($metadata);
        } catch (\Throwable) {
            return false;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            unlink($temporary);
        }
    }

    /**
     * Actual ffprobe output only; never use client-supplied media.meta.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function validVideoMetadata(array $metadata): bool
    {
        $duration = data_get($metadata, 'format.duration');
        $formats = explode(',', (string) data_get($metadata, 'format.format_name', ''));
        if (! in_array('mp4', $formats, true) || ! is_numeric($duration) || ! is_finite((float) $duration)
            || (float) $duration <= 0 || (float) $duration > 140) {
            return false;
        }
        $videoCount = 0;
        $audioCount = 0;
        foreach ($metadata['streams'] ?? [] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && ($stream['codec_name'] ?? '') === 'h264') {
                $videoCount++;
            } elseif (($stream['codec_type'] ?? '') === 'audio' && ($stream['codec_name'] ?? '') === 'aac') {
                $audioCount++;
            } else {
                return false;
            }
            if (isset($stream['duration']) && (! is_numeric($stream['duration']) || (float) $stream['duration'] > 140)) {
                return false;
            }
        }

        return $videoCount === 1 && $audioCount <= 1;
    }
}
