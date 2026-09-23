<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\LinkedInPageDriver;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Services\StorageManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a copy of each connected account's profile picture in the app's own
 * storage.
 *
 * Providers hand out picture links that are not meant to be kept. Meta signs
 * Facebook and Instagram links with an `oe=` expiry about four days out;
 * TikTok's `x-expires` is hours. Storing the link meant every Page's picture
 * broke on that schedule, which is what clients saw a few days after
 * connecting. YouTube and X links happen to be permanent, which is why only
 * some channels broke.
 */
class SocialAvatarStore
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    private const EXTENSIONS = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    private const GRAPH = 'https://graph.facebook.com/v25.0';

    public function __construct(
        private readonly StorageManager $storage,
        private readonly SocialAccessTokenService $tokens,
    ) {}

    /**
     * Copy a picture into storage and point the account at the copy.
     *
     * With no source given, the account's own provider link is used — right
     * after connecting, while it is still valid. Returns whether a copy now
     * exists; failure leaves the account exactly as it was.
     */
    public function store(SocialAccount $account, ?string $sourceUrl = null): bool
    {
        $url = $sourceUrl ?? $account->providerPictureUrl();
        if (! $this->isFetchable($url)) {
            return false;
        }

        $bytes = $this->download($url, $account);
        if ($bytes === null) {
            return false;
        }

        $info = @getimagesizefromstring($bytes);
        $extension = $info ? (self::EXTENSIONS[$info[2]] ?? null) : null;
        if ($extension === null) {
            return false;
        }

        // Named after the content, so an unchanged picture is recognised and
        // not rewritten on every refresh.
        $path = $this->storage->prefixedPath(sprintf(
            'social-avatars/%d/%d-%s.%s',
            $account->workspace_id,
            $account->id,
            substr(sha1($bytes), 0, 16),
            $extension,
        ));
        $diskName = $this->storage->diskName();
        if ($account->picture_path === $path && $account->picture_disk === $diskName) {
            return true;
        }

        if (! $this->storage->disk()->put($path, $bytes, ['visibility' => 'public'])) {
            Log::warning('Social avatar could not be written to storage', ['account_id' => $account->id]);

            return false;
        }

        $previous = [$account->picture_path, $account->picture_disk];
        $attributes = ['picture_path' => $path, 'picture_disk' => $diskName];
        if ($sourceUrl !== null) {
            $attributes['picture_url'] = $sourceUrl;
        }
        // Quietly: this save must not look like a new picture link and queue
        // another copy of the copy.
        $account->forceFill($attributes)->saveQuietly();

        if ($previous[0] && $previous[0] !== $path) {
            try {
                Storage::disk($previous[1] ?: $diskName)->delete($previous[0]);
            } catch (\Throwable) {
                // A leftover file is harmless; a failed delete must not undo
                // a successful update.
            }
        }

        return true;
    }

    /**
     * A current picture link, asked for from the provider.
     *
     * For accounts whose stored link has already expired: the only way back
     * is to ask again with the account's own token.
     */
    public function freshPictureUrl(SocialAccount $account): ?string
    {
        try {
            $account = $this->tokens->fresh($account);
            $token = (string) $account->access_token;

            return match ($account->network) {
                'facebook' => Http::timeout(15)->get(self::GRAPH."/{$account->account_id}/picture", [
                    'type' => 'large', 'redirect' => 'false', 'access_token' => $token,
                ])->json('data.url'),
                'instagram' => Http::timeout(15)->get(self::GRAPH."/{$account->account_id}", [
                    'fields' => 'profile_picture_url', 'access_token' => $token,
                ])->json('profile_picture_url'),
                'linkedin_page' => collect((new LinkedInPageDriver)->organizations($token))
                    ->firstWhere('account_id', (string) $account->account_id)['picture_url'] ?? null,
                'linkedin' => (new LinkedInDriver)->fetchAccountInfo($token)['picture_url'] ?? null,
                'youtube' => (new YoutubeDriver)->fetchAccountInfo($token)['picture_url'] ?? null,
                'tiktok' => (new TikTokDriver)->fetchAccountInfo($token)['picture_url'] ?? null,
                'twitter' => (new XDriver)->fetchAccountInfo($token)['picture_url'] ?? null,
                default => null,
            };
        } catch (\Throwable $e) {
            Log::info('Fresh social avatar could not be fetched', [
                'account_id' => $account->id,
                'network' => $account->network,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return null;
        }
    }

    /**
     * The link comes from a provider's API response, but it is still fetched
     * from the server, so it gets the same checks as any outbound URL:
     * HTTPS, a public address, and no redirects to somewhere else.
     */
    private function isFetchable(?string $url): bool
    {
        if (! is_string($url) || ! str_starts_with(strtolower($url), 'https://')) {
            return false;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || strtolower($host) === 'localhost') {
            return false;
        }
        $ip = gethostbyname($host);

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function download(string $url, SocialAccount $account): ?string
    {
        try {
            $response = Http::withoutRedirecting()->timeout(15)->get($url);
        } catch (\Throwable $e) {
            Log::info('Social avatar download failed', ['account_id' => $account->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }

        $bytes = $response->successful() ? $response->body() : '';

        return $bytes !== '' && strlen($bytes) <= self::MAX_BYTES ? $bytes : null;
    }
}
