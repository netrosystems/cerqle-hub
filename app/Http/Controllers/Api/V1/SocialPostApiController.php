<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialMediaLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SocialPostApiController extends WorkspaceScopedController
{
    public function __construct(private readonly SocialMediaLifecycleService $mediaLifecycle) {}

    /**
     * GET /api/v1/social/accounts
     */
    public function accounts(Request $request): JsonResponse
    {
        $accounts = SocialAccount::where('workspace_id', $this->workspaceId($request))
            ->where('active', true)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'network' => $a->network,
                'name' => $a->name,
                'picture_url' => $a->picture_url,
                'created_at' => $a->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $accounts]);
    }

    /**
     * POST /api/v1/social/posts
     * Schedule or immediately publish a post.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:63206'],
            'title' => ['nullable', 'string', 'max:256'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['url', 'regex:/^https:\/\//i', 'max:2048'],
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['integer'],
            'platform_payloads' => ['nullable', 'array'],
            'platform_payloads.*.customize' => ['nullable', 'boolean'],
            'platform_payloads.*.title' => ['nullable', 'string', 'max:256'],
            'platform_payloads.*.body' => ['nullable', 'string', 'max:63206'],
            'platform_payloads.*.media_urls' => ['nullable', 'array'],
            'platform_payloads.*.media_urls.*' => ['url', 'regex:/^https:\/\//i', 'max:2048'],
            'platform_payloads.*.media_ids' => ['nullable', 'array'],
            'platform_payloads.*.media_ids.*' => ['integer'],
            'platform_payloads.*.options' => ['nullable', 'array'],
            'platform_payloads.*.options.link_url' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'platform_payloads.*.options.thumbnail_url' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'platform_payloads.*.options.thumbnail_media_id' => ['nullable', 'integer'],
            'platform_payloads.*.options.tags' => ['nullable', 'array', 'max:50'],
            'platform_payloads.*.options.tags.*' => ['string', 'max:60'],
            'platform_payloads.*.options.privacy_status' => ['nullable', 'in:private,unlisted,public'],
            'platform_payloads.*.options.privacy_level' => ['nullable', 'string', 'max:64'],
            'platform_payloads.*.options.consent' => ['nullable', 'boolean'],
            'platform_payloads.*.account_options' => ['nullable', 'array'],
            'platform_payloads.*.account_options.*' => ['nullable', 'array'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'youtube_options' => ['nullable', 'array'],
            'youtube_options.thumbnail_url' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'youtube_options.thumbnail_media_id' => ['nullable', 'integer'],
            'youtube_options.privacy_status' => ['nullable', 'in:private,unlisted,public'],
            'youtube_options.tags' => ['nullable', 'array', 'max:50'],
            'youtube_options.tags.*' => ['string', 'max:60'],
            'youtube_options.category_id' => ['nullable', 'integer', 'between:1,44'],
            'youtube_options.playlist_id' => ['nullable', 'string', 'max:128'],
            'youtube_options.made_for_kids' => ['nullable', 'boolean'],
            'youtube_options.contains_synthetic_media' => ['nullable', 'boolean'],
            'youtube_options.notify_subscribers' => ['nullable', 'boolean'],
            'youtube_options.default_language' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $wsId = $this->workspaceId($request);
        $validated['account_ids'] = array_values(array_unique(array_map('intval', $validated['account_ids'])));

        // Verify all account IDs belong to this workspace
        $accounts = SocialAccount::where('workspace_id', $wsId)
            ->whereIn('id', $validated['account_ids'])
            ->where('active', true)
            ->get(['id', 'network']);

        if ($accounts->count() !== count($validated['account_ids'])) {
            return response()->json(['error' => 'One or more account_ids are invalid.'], 422);
        }

        $networks = $accounts->pluck('network')->unique();
        $mediaUrls = array_values(array_filter($validated['media_urls'] ?? []));
        $validated['media_urls'] = $mediaUrls;
        $this->validateContent($networks, $validated, $mediaUrls, $accounts);

        $mediaIds = collect($validated['media_ids'] ?? [])
            ->push(data_get($validated, 'youtube_options.thumbnail_media_id'))
            ->merge(collect($validated['platform_payloads'] ?? [])->flatMap(fn ($payload) => $payload['media_ids'] ?? []))
            ->merge(collect($validated['platform_payloads'] ?? [])->pluck('options.thumbnail_media_id'))
            ->filter()->unique()->values()->all();

        $post = DB::transaction(function () use ($wsId, $validated, $request, $mediaIds): SocialPost {
            $post = SocialPost::create([
                'workspace_id' => $wsId,
                'body' => $validated['body'] ?? '',
                'title' => $validated['title'] ?? null,
                'media_urls' => $validated['media_urls'] ?? [],
                'youtube_options' => $validated['youtube_options'] ?? null,
                'platform_payloads' => $validated['platform_payloads'] ?? null,
                'target_accounts' => $validated['account_ids'],
                'scheduled_at' => $validated['scheduled_at'] ?? null,
                'status' => ! empty($validated['scheduled_at']) ? 'scheduled' : 'publishing',
            ]);
            $this->mediaLifecycle->syncPostMedia($post, $request->user(), $mediaIds);

            return $post;
        });

        if (empty($validated['scheduled_at'])) {
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
        }

        return response()->json([
            'id' => $post->id,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'created_at' => $post->created_at->toIso8601String(),
        ], 201);
    }

    private function validateContent(Collection $networks, array $validated, array $mediaUrls, Collection $accounts): void
    {
        $errors = [];
        $payloads = (array) ($validated['platform_payloads'] ?? []);
        $unexpected = array_diff(array_keys($payloads), $networks->all());
        if ($unexpected !== []) {
            $errors['platform_payloads'] = ['Platform overrides may only target selected social networks.'];
        }

        $limits = ['tiktok' => 2200, 'linkedin' => 3000, 'facebook' => 63206, 'instagram' => 2200, 'youtube' => 5000];
        $effectiveVideos = [];
        foreach ($networks as $network) {
            $override = (array) ($payloads[$network] ?? []);
            $customize = (bool) ($override['customize'] ?? false);
            $title = trim((string) ($customize ? ($override['title'] ?? '') : ($validated['title'] ?? '')));
            $body = trim((string) ($customize ? ($override['body'] ?? '') : ($validated['body'] ?? '')));
            $media = array_values(array_filter($customize ? ($override['media_urls'] ?? []) : $mediaUrls));
            $options = array_merge(
                $network === 'youtube' ? (array) ($validated['youtube_options'] ?? []) : [],
                (array) ($override['options'] ?? [])
            );

            if ($network !== 'youtube' && $body === '') {
                $errors["platform_payloads.{$network}.body"] = [ucfirst($network).' content is required.'];
            }
            if (mb_strlen($body) > ($limits[$network] ?? 5000)) {
                $errors["platform_payloads.{$network}.body"] = [ucfirst($network).' content exceeds its character limit.'];
            }
            if ($network === 'instagram' && $media === []) {
                $errors['platform_payloads.instagram.media_urls'] = ['Instagram requires compatible media.'];
            }
            if ($network === 'youtube') {
                if ($title === '' || count($media) !== 1) {
                    $errors['platform_payloads.youtube'] = ['YouTube requires a title and exactly one video.'];
                } elseif (mb_strlen($title) > 100) {
                    $errors['platform_payloads.youtube.title'] = ['YouTube video titles cannot exceed 100 characters.'];
                }
                $tags = array_map(fn ($tag) => trim((string) $tag), $options['tags'] ?? []);
                if (mb_strlen(implode(',', $tags)) > 500) {
                    $errors['platform_payloads.youtube.options.tags'] = ['YouTube tags cannot exceed 500 total characters.'];
                }
                if (! empty($options['playlist_id']) && $accounts->where('network', 'youtube')->count() !== 1) {
                    $errors['platform_payloads.youtube.options.playlist_id'] = ['Playlist placement requires exactly one YouTube channel.'];
                }
                $effectiveVideos = array_merge($effectiveVideos, $media);
            }
            if ($network === 'tiktok') {
                if (count($media) !== 1) {
                    $errors['platform_payloads.tiktok.media_urls'] = ['TikTok requires exactly one video.'];
                }
                foreach ($accounts->where('network', 'tiktok') as $account) {
                    $accountOptions = array_merge($options, (array) data_get($override, 'account_options.'.$account->id, []));
                    if (empty($accountOptions['privacy_level'])) {
                        $errors['platform_payloads.tiktok.options.privacy_level'] = ['Choose a TikTok privacy level for every creator.'];
                    }
                    if (! ($accountOptions['consent'] ?? false)) {
                        $errors['platform_payloads.tiktok.options.consent'] = ['Confirm publishing consent for every TikTok creator.'];
                    }
                }
                $effectiveVideos = array_merge($effectiveVideos, $media);
            }
        }

        $blockedHosts = ['youtube.com', 'youtu.be', 'tiktok.com', 'drive.google.com', 'docs.google.com'];
        if ($effectiveVideos !== []) {
            foreach ($effectiveVideos as $index => $url) {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                foreach ($blockedHosts as $blockedHost) {
                    if ($host === $blockedHost || str_ends_with($host, '.'.$blockedHost)) {
                        $errors["media_urls.{$index}"] = ['Use a direct public video-file URL, not a watch or cloud-drive preview page.'];
                        break;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
