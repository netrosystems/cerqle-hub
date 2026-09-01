<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(private StorageManager $storageManager) {}

    public function store(
        UploadedFile $file,
        Model $owner,
        string $collection = 'default',
        ?string $disk = null
    ): Media {
        $ext = $file->getClientOriginalExtension();
        $filename = $file->getClientOriginalName();

        // Resolve disk from StorageManager unless caller explicitly passes one
        $resolvedDisk = $disk ?? $this->storageManager->diskName();
        $rawPath = 'media/'.Str::uuid().'.'.$ext;
        $path = $this->storageManager->prefixedPath($rawPath);

        $written = Storage::disk($resolvedDisk)->putFileAs(dirname($path), $file, basename($path));
        if ($written === false) {
            throw new \RuntimeException('The configured storage provider rejected the media upload.');
        }

        $temporary = in_array($collection, ['social', 'social-video', 'social-thumbnail'], true);

        return Media::create([
            'mediable_type' => get_class($owner),
            'mediable_id' => $owner->getKey(),
            'disk' => $resolvedDisk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'collection' => $collection,
            'is_temporary' => $temporary,
            // An upload is an orphan until a post references it. This prevents
            // abandoned composer uploads from consuming storage forever.
            'purge_after' => $temporary ? now()->addDay() : null,
        ]);
    }

    /**
     * Get total storage used by owner in bytes.
     */
    public function usedBytes(Model $owner, ?string $collection = null): int
    {
        $query = Media::whereNull('quota_released_at');

        // Client plans are organization-wide. Count media owned by every team
        // member so changing users cannot create a separate quota bucket.
        if ($owner instanceof User && $owner->client_id) {
            $query->where('mediable_type', User::class)
                ->whereIn('mediable_id', $owner->client()->firstOrFail()->users()->select('id'));
        } else {
            $query->where('mediable_type', get_class($owner))
                ->where('mediable_id', $owner->getKey());
        }

        if ($collection) {
            $query->where('collection', $collection);
        }

        return (int) $query->sum('size_bytes');
    }

    /**
     * Get storage quota in bytes from the plan's storage limit (stored in MB).
     */
    public function quotaBytes(User $user): int
    {
        $plan = $user->effectiveSubscription()?->plan;
        $limits = $plan?->limits;

        if (is_array($limits) && array_key_exists('storage', $limits)) {
            // A null plan limit represents unlimited storage.
            if ($limits['storage'] === null) {
                return PHP_INT_MAX;
            }

            return max(0, (int) $limits['storage']) * 1024 * 1024;
        }

        // Preserve a safe default for users without an assigned plan.
        return 1024 * 1024 * 1024;
    }

    /** @return array{used_bytes:int,quota_bytes:?int,remaining_bytes:?int,percent_used:float,unlimited:bool,is_full:bool} */
    public function usage(User $user): array
    {
        $used = $this->usedBytes($user);
        $rawQuota = $this->quotaBytes($user);
        $unlimited = $rawQuota === PHP_INT_MAX;
        $quota = $unlimited ? null : $rawQuota;
        $remaining = $unlimited ? null : max(0, $rawQuota - $used);
        $percent = $unlimited ? 0.0 : ($rawQuota <= 0 ? 100.0 : min(100, round(($used / $rawQuota) * 100, 1)));

        return [
            'used_bytes' => $used,
            'quota_bytes' => $quota,
            'remaining_bytes' => $remaining,
            'percent_used' => $percent,
            'unlimited' => $unlimited,
            'is_full' => ! $unlimited && $used >= $rawQuota,
        ];
    }
}
