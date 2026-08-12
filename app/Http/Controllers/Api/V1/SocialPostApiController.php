<?php

namespace App\Http\Controllers\Api\V1;

use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SocialPostApiController extends WorkspaceScopedController
{
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
            'body' => ['nullable', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:256'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['url', 'regex:/^https:\/\//i', 'max:2048'],
            'account_ids' => ['required', 'array', 'min:1'],
            'account_ids.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'youtube_options' => ['nullable', 'array'],
            'youtube_options.thumbnail_url' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
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

        $post = SocialPost::create([
            'workspace_id' => $wsId,
            'body' => $validated['body'] ?? '',
            'title' => $validated['title'] ?? null,
            'media_urls' => $validated['media_urls'] ?? [],
            'youtube_options' => $validated['youtube_options'] ?? null,
            'target_accounts' => $validated['account_ids'],
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'status' => ! empty($validated['scheduled_at']) ? 'scheduled' : 'publishing',
        ]);

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
        $isYoutubeOnly = $networks->count() === 1 && $networks->contains('youtube');

        if (! $isYoutubeOnly && trim((string) ($validated['body'] ?? '')) === '') {
            $errors['body'] = ['Post content is required for the selected social networks.'];
        }

        if ($networks->contains('instagram') && $mediaUrls === []) {
            $errors['media_urls'] = ['Instagram publishing requires at least one publicly reachable image URL.'];
        }

        if ($networks->intersect(['youtube', 'tiktok'])->isNotEmpty() && $mediaUrls === []) {
            $errors['media_urls'] = ['YouTube and TikTok publishing require a publicly reachable video URL.'];
        }

        if ($networks->contains('youtube')) {
            $title = trim((string) ($validated['title'] ?? ''));
            if ($title === '') {
                $errors['title'] = ['A YouTube video title is required.'];
            } elseif (mb_strlen($title) > 100) {
                $errors['title'] = ['YouTube video titles cannot exceed 100 characters.'];
            }
            if (count($mediaUrls) !== 1) {
                $errors['media_urls'] = ['YouTube requires exactly one video file.'];
            }

            $tags = array_map(fn ($tag) => trim((string) $tag), $validated['youtube_options']['tags'] ?? []);
            if (mb_strlen(implode(',', $tags)) > 500) {
                $errors['youtube_options.tags'] = ['YouTube tags cannot exceed 500 total characters.'];
            }

            if (! empty($validated['youtube_options']['playlist_id']) && $accounts->where('network', 'youtube')->count() !== 1) {
                $errors['youtube_options.playlist_id'] = ['Playlist placement requires exactly one selected YouTube channel.'];
            }
        }

        $blockedHosts = ['youtube.com', 'youtu.be', 'tiktok.com', 'drive.google.com', 'docs.google.com'];
        if ($networks->intersect(['youtube', 'tiktok'])->isNotEmpty()) {
            foreach ($mediaUrls as $index => $url) {
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
