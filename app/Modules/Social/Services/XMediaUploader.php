<?php

namespace App\Modules\Social\Services;

use App\Models\Media;
use App\Modules\Social\Models\SocialAccount;
use App\Services\StorageManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class XMediaUploader
{
    private const CHUNK_BYTES = 4 * 1024 * 1024;

    /**
     * One provider request per call. Caller encrypts/persists state, refreshes
     * credentials each stage, and rechecks readiness immediately before create.
     * uploads is keyed by local Media ID; media_ids contains ordered X IDs.
     *
     * @param  array<int, int|string>  $mediaIds
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function advance(SocialAccount $account, array $mediaIds, array $state): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        $media = Media::query()->whereIn('id', $ids)
            ->whereHas('socialPosts', fn ($query) => $query->where('workspace_id', $account->workspace_id))->get()->keyBy('id');
        if ($media->count() !== count($ids) || count($ids) > 4 || in_array(0, $ids, true)) {
            throw new XProviderException('X media is missing or not linked to this workspace.', 'media');
        }
        $uploads = $state['uploads'] ?? [];
        if (($state['account_id'] ?? $account->getKey()) !== $account->getKey()) {
            $uploads = [];
        }
        $uploads = array_intersect_key($uploads, array_flip($ids));
        foreach ($ids as $id) {
            if (($uploads[$id]['expires_at'] ?? 0) <= now()->timestamp + 30) {
                unset($uploads[$id]);
            }
        }
        foreach ($ids as $id) {
            $item = $media[$id];
            $upload = $uploads[$id] ?? ['stage' => 'initialize'];
            if ($upload['stage'] === 'ready') {
                continue;
            }
            if (($upload['next_check_at'] ?? 0) > now()->timestamp) {
                break;
            }
            $request = Http::withToken($account->access_token)->acceptJson()->withoutRedirecting()->timeout(30);
            $base = 'https://api.x.com/2/media/upload';
            try {
                if ($upload['stage'] === 'initialize') {
                    $mime = $item->mime_type;
                    $category = $mime === 'image/gif' ? 'tweet_gif' : (str_starts_with($mime, 'video/') ? 'tweet_video' : 'tweet_image');
                    if ((! str_starts_with($mime, 'image/') && ! str_starts_with($mime, 'video/')) || $item->size_bytes <= 0 || $item->size_bytes > 500 * 1024 * 1024 || ($category !== 'tweet_image' && count($ids) !== 1)) {
                        throw new XProviderException('Unsupported X media type, size, or combination.', 'media');
                    }
                    $response = $request->post($base.'/initialize', ['media_type' => $mime, 'media_category' => $category, 'total_bytes' => $item->size_bytes]);
                } elseif ($upload['stage'] === 'append') {
                    app(StorageManager::class)->ensureDiskReady($item->disk);
                    $stream = Storage::disk($item->disk)->readStream($item->path);
                    if (! is_resource($stream)) {
                        throw new XProviderException('X media file cannot be read.', 'media');
                    }
                    try {
                        if (fseek($stream, $upload['offset']) !== 0) {
                            throw new XProviderException('X media stream cannot seek to the next chunk.', 'media');
                        }
                        $chunk = stream_get_contents($stream, min(self::CHUNK_BYTES, $item->size_bytes - $upload['offset']));
                    } finally {
                        fclose($stream);
                    }
                    if ($chunk === false || strlen($chunk) !== min(self::CHUNK_BYTES, $item->size_bytes - $upload['offset'])) {
                        throw new XProviderException('X media file is incomplete.', 'media');
                    }
                    $response = $request->attach('media', $chunk, 'chunk.bin', ['Content-Type' => 'application/octet-stream'])
                        ->post($base.'/'.$upload['id'].'/append', ['segment_index' => $upload['segment_index']]);
                } elseif ($upload['stage'] === 'finalize') {
                    $response = $request->post($base.'/'.$upload['id'].'/finalize');
                } else {
                    $response = $request->get($base, ['media_id' => $upload['id']]);
                }
            } catch (ConnectionException) {
                throw new XProviderException('X media connection failed.', 'transient', 60);
            }
            if (! $response->successful()) {
                throw XProviderException::fromResponse($response);
            }
            $data = $response->json('data') ?? [];
            if ($upload['stage'] === 'initialize') {
                if (! is_string($data['id'] ?? null) || ! preg_match('/^[0-9]{1,19}$/', $data['id']) || ($data['expires_after_secs'] ?? 0) <= 30) {
                    throw new XProviderException('X returned an incomplete upload session.', 'media');
                }
                $upload = ['id' => $data['id'], 'expires_at' => now()->timestamp + (int) $data['expires_after_secs'], 'offset' => 0, 'segment_index' => 0, 'stage' => 'append'];
            } elseif ($upload['stage'] === 'append') {
                $upload['offset'] += strlen($chunk);
                $upload['segment_index']++;
                $upload['stage'] = $upload['offset'] >= $item->size_bytes ? 'finalize' : 'append';
                if (isset($data['expires_at'])) {
                    $upload['expires_at'] = (int) $data['expires_at'];
                }
            } else {
                $processing = $data['processing_info'] ?? null;
                $status = $processing['state'] ?? null;
                if ($status === 'failed') {
                    throw new XProviderException('X could not process this media.', 'media');
                }
                if ($status === 'succeeded' || ($processing === null && $upload['stage'] === 'finalize')) {
                    $upload['stage'] = 'ready';
                    unset($upload['next_check_at']);
                } elseif (in_array($status, ['pending', 'in_progress'], true)) {
                    $upload['stage'] = 'processing';
                    $upload['next_check_at'] = now()->timestamp + min(900, max(1, (int) ($processing['check_after_secs'] ?? 5)));
                } else {
                    throw new XProviderException('X returned an invalid processing status.', 'media');
                }
                if (isset($data['expires_after_secs'])) {
                    $upload['expires_at'] = now()->timestamp + (int) $data['expires_after_secs'];
                }
            }
            $uploads[$id] = $upload;
            break;
        }
        $ready = count($uploads) === count($ids) && collect($uploads)->every(fn ($upload) => $upload['stage'] === 'ready');
        $processing = collect($uploads)->first(fn ($upload) => $upload['stage'] === 'processing');

        return ['account_id' => $account->getKey(), 'uploads' => $uploads, 'stage' => $ready ? 'ready' : ($processing ? 'processing' : 'uploading'), 'retry_after' => $ready ? 0 : max(1, ($processing['next_check_at'] ?? now()->timestamp + 1) - now()->timestamp), 'media_ids' => $ready ? array_map(fn ($id) => $uploads[$id]['id'], $ids) : []];
    }
}
