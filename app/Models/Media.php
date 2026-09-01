<?php

namespace App\Models;

use App\Modules\Social\Models\SocialPost;
use App\Services\StorageManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'disk',
        'path',
        'filename',
        'mime_type',
        'size_bytes',
        'collection',
        'is_temporary',
        'quota_released_at',
        'purge_after',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'size_bytes' => 'integer',
        'is_temporary' => 'boolean',
        'quota_released_at' => 'datetime',
        'purge_after' => 'datetime',
    ];

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    public function socialPosts(): BelongsToMany
    {
        return $this->belongsToMany(
            SocialPost::class,
            'media_social_post',
            'media_id',
            'social_post_id'
        )->withTimestamps();
    }

    public function url(): string
    {
        $this->ensureDiskConfigured();

        return Storage::disk($this->disk)->url($this->path);
    }

    public function delete(): bool
    {
        $this->ensureDiskConfigured();
        if (! Storage::disk($this->disk)->delete($this->path)) {
            throw new \RuntimeException('The storage provider could not delete the media object.');
        }

        return parent::delete();
    }

    private function ensureDiskConfigured(): void
    {
        app(StorageManager::class)->ensureDiskReady($this->disk);
    }
}
