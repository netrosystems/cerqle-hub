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

        return Media::create([
            'mediable_type' => get_class($owner),
            'mediable_id' => $owner->getKey(),
            'disk' => $resolvedDisk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'collection' => $collection,
        ]);
    }

    /**
     * Get total storage used by owner in bytes.
     */
    public function usedBytes(Model $owner, ?string $collection = null): int
    {
        $query = Media::where('mediable_type', get_class($owner))
            ->where('mediable_id', $owner->getKey());

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
}
