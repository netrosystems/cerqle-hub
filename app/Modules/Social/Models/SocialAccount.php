<?php

namespace App\Modules\Social\Models;

use App\Modules\Social\Jobs\CacheSocialAvatarJob;
use App\Services\StorageManager;
use App\Support\Concerns\EnforcesChannelPlanLimit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SocialAccount extends Model
{
    use EnforcesChannelPlanLimit;

    protected static function booted(): void
    {
        static::addGlobalScope('connected', fn ($query) => $query->whereNull('social_media_accounts.disconnected_at'));

        // Copy the picture whenever a new link arrives, whichever code path
        // saved it — connect, reconnect, a page discovered later. Doing it here
        // rather than in each controller means a future network cannot forget.
        static::saved(function (SocialAccount $account): void {
            if (! $account->wasRecentlyCreated && ! $account->wasChanged('picture_url')) {
                return;
            }
            if (! self::isRemotePicture($account->providerPictureUrl())) {
                return;
            }

            CacheSocialAvatarJob::dispatch($account->id)->onQueue('social')->afterCommit();
        });
    }

    /**
     * The provider's own picture link, as stored — bypassing the accessor,
     * which serves the local copy.
     *
     * Read from the live attributes, not getRawOriginal(): inside a saved
     * event the originals are not synced yet, so that returns nothing for a
     * new account and the previous link for an updated one.
     */
    public function providerPictureUrl(): ?string
    {
        return $this->attributes['picture_url'] ?? null;
    }

    public static function isRemotePicture(?string $url): bool
    {
        return is_string($url) && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * Serve the stored copy when there is one.
     *
     * The provider's link is kept in the column as the source, but Meta and
     * TikTok links expire on their own, so it is only a fallback until a copy
     * exists. Same approach as ChatWidget's managed avatar.
     */
    public function getPictureUrlAttribute(?string $providerUrl): ?string
    {
        if (! $this->picture_path) {
            return $providerUrl;
        }

        $storageManager = app(StorageManager::class);
        $disk = $this->picture_disk ?: $storageManager->diskName();
        $storageManager->ensureDiskReady($disk);

        return Storage::disk($disk)->url($this->picture_path);
    }

    protected function channelPlanLimitKey(): string
    {
        return 'social_accounts';
    }

    protected $table = 'social_media_accounts';

    protected $fillable = ['workspace_id', 'network', 'account_id', 'name', 'picture_url', 'picture_path', 'picture_disk', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'meta', 'active', 'disconnected_at'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'meta' => 'array',
            'active' => 'boolean',
            'token_expires_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function posts()
    {
        return $this->belongsToMany(SocialPost::class, 'social_media_post_accounts', 'social_account_id', 'post_id');
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
