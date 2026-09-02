<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Modules\AI\Exceptions\AiCreditsExhaustedException;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\SocialMediaLifecycleService;
use App\Modules\Social\Services\SocialPublisher;
use App\Services\MediaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SocialPostController extends Controller
{
    public function __construct(
        private readonly SocialMediaLifecycleService $mediaLifecycle,
        private readonly MediaService $mediaService,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function validateDirectVideoUrls(Collection $selectedNetworks, array $mediaUrls): void
    {
        if ($selectedNetworks->intersect(['youtube', 'tiktok'])->isEmpty()) {
            return;
        }

        $blockedHosts = ['youtube.com', 'youtu.be', 'tiktok.com', 'drive.google.com', 'docs.google.com'];

        foreach ($mediaUrls as $index => $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $isWebPage = collect($blockedHosts)->contains(
                fn (string $blockedHost) => $host === $blockedHost || str_ends_with($host, '.'.$blockedHost)
            );

            if ($isWebPage) {
                throw ValidationException::withMessages([
                    "media_urls.{$index}" => [
                        'YouTube and TikTok need the actual video file. Choose Upload, or paste a public HTTPS URL that downloads the video directly (for example, https://cdn.example.com/video.mp4). YouTube watch, Shorts, TikTok, and cloud-drive preview links cannot be published.',
                    ],
                    'media_urls' => [
                        'This is a webpage link, not a direct video file. Choose Upload or use a direct public video-file URL.',
                    ],
                ]);
            }
        }
    }

    private function validatePlatformPayloads(Collection $selectedNetworks, array $validated, Collection $accounts): void
    {
        $payloads = (array) ($validated['platform_payloads'] ?? []);
        $unexpected = array_diff(array_keys($payloads), $selectedNetworks->all());
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'platform_payloads' => ['Platform overrides may only be supplied for selected social networks.'],
            ]);
        }

        $limits = ['tiktok' => 2200, 'linkedin' => 3000, 'facebook' => 63206, 'instagram' => 2200, 'youtube' => 5000];
        $errors = [];

        foreach ($selectedNetworks as $network) {
            $override = (array) ($payloads[$network] ?? []);
            $hasOverride = array_key_exists($network, $payloads);
            $customize = (bool) ($override['customize'] ?? false);
            $title = trim((string) ($customize ? ($override['title'] ?? '') : ($validated['title'] ?? '')));
            $body = trim((string) ($customize ? ($override['body'] ?? '') : ($validated['body'] ?? '')));
            $media = array_values(array_filter($customize ? ($override['media_urls'] ?? []) : ($validated['media_urls'] ?? [])));
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
            if ($network === 'youtube') {
                if ($title === '') {
                    $errors[$hasOverride ? 'platform_payloads.youtube.title' : 'title'] = ['A YouTube video title is required.'];
                }
                if (count($media) !== 1) {
                    $errors[$hasOverride ? 'platform_payloads.youtube.media_urls' : 'media_urls'] = ['YouTube requires exactly one video.'];
                }
                if ($title !== '' && mb_strlen($title) > 100) {
                    $errors[$hasOverride ? 'platform_payloads.youtube.title' : 'title'] = ['YouTube video titles cannot exceed 100 characters.'];
                }
                $tags = array_map(fn ($tag) => trim((string) $tag), $options['tags'] ?? []);
                if (mb_strlen(implode(',', $tags)) > 500) {
                    $errors[$hasOverride ? 'platform_payloads.youtube.options.tags' : 'youtube_options.tags'] = ['YouTube tags cannot exceed 500 total characters.'];
                }
                if (! empty($options['playlist_id']) && $accounts->where('network', 'youtube')->count() !== 1) {
                    $errors[$hasOverride ? 'platform_payloads.youtube.options.playlist_id' : 'youtube_options.playlist_id'] = ['Playlist placement requires exactly one YouTube channel.'];
                }
                $this->validateDirectVideoUrls(collect(['youtube']), $media);
            }
            if ($network === 'instagram' && $media === []) {
                $errors["platform_payloads.{$network}.media_urls"] = ['Instagram requires compatible media.'];
            }
            if ($network === 'tiktok') {
                if (count($media) !== 1) {
                    $errors['platform_payloads.tiktok.media_urls'] = ['TikTok requires exactly one video.'];
                }
                foreach ($accounts->where('network', 'tiktok') as $account) {
                    $accountOptions = array_merge($options, (array) data_get($override, 'account_options.'.$account->id, []));
                    if (empty($accountOptions['privacy_level'])) {
                        $errors['platform_payloads.tiktok.options.privacy_level'] = ['Choose a TikTok privacy level for every selected creator.'];
                    }
                    if (! ($accountOptions['consent'] ?? false)) {
                        $errors['platform_payloads.tiktok.options.consent'] = ['Confirm publishing consent for every selected TikTok creator.'];
                    }
                }
                $this->validateDirectVideoUrls(collect(['tiktok']), $media);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function youtubeValidationRules(): array
    {
        return [
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
        ];
    }

    private function validateUploadedMediaCompatibility(Collection $selectedNetworks, array $validated, Request $request): void
    {
        $payloads = (array) ($validated['platform_payloads'] ?? []);
        $allIds = collect($validated['media_ids'] ?? [])
            ->merge(collect($payloads)->flatMap(fn ($payload) => $payload['media_ids'] ?? []))
            ->filter()->map(fn ($id) => (int) $id)->unique();

        if ($allIds->isEmpty()) {
            return;
        }

        $user = $request->user();
        $mediaQuery = Media::query()
            ->whereIn('id', $allIds)
            ->where('mediable_type', $user::class)
            ->where('is_temporary', true);
        if ($user->client_id) {
            $mediaQuery->whereIn('mediable_id', $user->client()->firstOrFail()->users()->select('id'));
        } else {
            $mediaQuery->where('mediable_id', $user->id);
        }
        $media = $mediaQuery->get()->keyBy('id');

        foreach ($selectedNetworks as $network) {
            $override = (array) ($payloads[$network] ?? []);
            $ids = collect(($override['customize'] ?? false) ? ($override['media_ids'] ?? []) : ($validated['media_ids'] ?? []))
                ->filter()->map(fn ($id) => (int) $id);
            $items = $ids->map(fn ($id) => $media->get($id))->filter();

            if ($items->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    "platform_payloads.{$network}.media_ids" => ['One or more uploaded files are invalid or belong to a different client.'],
                ]);
            }

            $hasVideo = $items->contains(fn (Media $item) => str_starts_with($item->mime_type, 'video/'));
            $hasNonVideo = $items->contains(fn (Media $item) => ! str_starts_with($item->mime_type, 'video/'));
            if (in_array($network, ['youtube', 'tiktok'], true) && $hasNonVideo) {
                throw ValidationException::withMessages([
                    "platform_payloads.{$network}.media_ids" => [ucfirst($network).' requires a compatible video file.'],
                ]);
            }
            if ($network === 'facebook' && $hasVideo && ($hasNonVideo || $items->count() > 1)) {
                throw ValidationException::withMessages([
                    'platform_payloads.facebook.media_ids' => ['Facebook video posts must use one video without mixed image attachments.'],
                ]);
            }
            if ($network === 'linkedin' && $items->count() > 1) {
                throw ValidationException::withMessages([
                    'platform_payloads.linkedin.media_ids' => ['LinkedIn supports one uploaded media item per Cerqle post.'],
                ]);
            }
        }
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $status = $request->query('status');
        $network = $request->query('network');

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Collect account IDs for the requested network filter
        $networkAccountIds = $network
            ? $accounts->where('network', $network)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $query = SocialPost::where('workspace_id', $wid)
            ->with([
                'accountLinks:id,post_id,social_account_id,status,platform_post_id',
                'media:id,disk,path,mime_type',
            ])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($network && $networkAccountIds->isNotEmpty(), function ($q) use ($networkAccountIds) {
                $q->where(function ($inner) use ($networkAccountIds) {
                    foreach ($networkAccountIds as $aid) {
                        $inner->orWhereJsonContains('target_accounts', $aid)
                            ->orWhereJsonContains('target_accounts', (int) $aid);
                    }
                });
            })
            ->orderByDesc('created_at');

        $posts = $query->paginate(20)->withQueryString();
        $posts->getCollection()->each(function (SocialPost $post) use ($accounts): void {
            $mediaMimeTypes = $post->media
                ->mapWithKeys(fn (Media $media) => [$media->url() => $media->mime_type])
                ->all();

            $post->setAttribute('media_mime_types', $mediaMimeTypes);

            // Older published posts may not have publish_results, but their
            // durable account link still identifies the remote post target.
            // Expose that authoritative state so the UI never hides the
            // platform-delete action for a live Instagram/Facebook post.
            $post->setAttribute(
                'published_account_ids',
                $post->accountLinks
                    ->where('status', 'published')
                    ->pluck('social_account_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all()
            );

            // Uploaded publishing files are deleted after the safety window.
            // Never send their expiring local URLs to published-history cards.
            // YouTube provides a durable thumbnail; other providers use the
            // card's platform placeholder until a provider thumbnail exists.
            if ($post->temporary_media_released_at) {
                $youtubeAccountIds = $accounts->where('network', 'youtube')->pluck('id')->map(fn ($id) => (int) $id);
                $youtubeLink = $post->accountLinks
                    ->where('status', 'published')
                    ->first(fn ($link) => $youtubeAccountIds->contains((int) $link->social_account_id));
                $post->setAttribute('media_urls', $youtubeLink?->platform_post_id
                    ? ['https://i.ytimg.com/vi/'.rawurlencode($youtubeLink->platform_post_id).'/hqdefault.jpg']
                    : []);
                $post->setAttribute('media_mime_types', $youtubeLink?->platform_post_id
                    ? ['https://i.ytimg.com/vi/'.rawurlencode($youtubeLink->platform_post_id).'/hqdefault.jpg' => 'image/jpeg']
                    : []);
            }
            $post->unsetRelation('accountLinks')->unsetRelation('media');
        });

        return Inertia::render('Social/Posts/Index', [
            'posts' => $posts,
            'accounts' => $accounts,
            'filters' => ['status' => $status, 'network' => $network],
        ]);
    }

    public function composer(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->where('active', true)->get(['id', 'network', 'name', 'picture_url']);

        return Inertia::render('Social/Composer', [
            'accounts' => $accounts,
            'storageUsage' => $this->mediaService->usage($request->user()),
        ]);
    }

    public function calendar(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $month = $request->query('month', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month), 422, 'Invalid month format.');

        $filterStatus = $request->query('status');
        $filterAccountId = $request->query('account_id');
        $filterNetwork = $request->query('network');

        $userTz = $request->user()?->timezone ?? 'Asia/Dhaka';
        try {
            $tz = new \DateTimeZone($userTz);
        } catch (\Exception) {
            $tz = new \DateTimeZone('Asia/Dhaka');
        }

        [$year, $mon] = explode('-', $month);
        $start = Carbon::createFromDate((int) $year, (int) $mon, 1, $tz)->startOfMonth()->utc();
        $end = Carbon::createFromDate((int) $year, (int) $mon, 1, $tz)->endOfMonth()->utc();

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Resolve account IDs for a network filter
        $networkAccountIds = $filterNetwork
            ? $accounts->where('network', $filterNetwork)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $posts = SocialPost::where('workspace_id', $wid)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$start, $end])
            ->when($filterStatus, fn ($q) => $q->where('status', $filterStatus))
            ->when($filterAccountId, function ($q) use ($filterAccountId) {
                $q->where(function ($inner) use ($filterAccountId) {
                    $inner->orWhereJsonContains('target_accounts', $filterAccountId)
                        ->orWhereJsonContains('target_accounts', (int) $filterAccountId);
                });
            })
            ->when($filterNetwork && $networkAccountIds->isNotEmpty(), function ($q) use ($networkAccountIds) {
                $q->where(function ($inner) use ($networkAccountIds) {
                    foreach ($networkAccountIds as $aid) {
                        $inner->orWhereJsonContains('target_accounts', $aid)
                            ->orWhereJsonContains('target_accounts', (int) $aid);
                    }
                });
            })
            ->get(['id', 'title', 'status', 'scheduled_at', 'timezone', 'target_accounts']);

        return Inertia::render('Social/Calendar', [
            'posts' => $posts,
            'month' => $month,
            'accounts' => $accounts,
            'filters' => [
                'status' => $filterStatus,
                'account_id' => $filterAccountId,
                'network' => $filterNetwork,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate(array_merge([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['nullable', 'string', 'max:63206'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['nullable', 'integer'],
            'platform_payloads' => ['nullable', 'array'],
            'platform_payloads.*.customize' => ['nullable', 'boolean'],
            'platform_payloads.*.title' => ['nullable', 'string', 'max:256'],
            'platform_payloads.*.body' => ['nullable', 'string', 'max:63206'],
            'platform_payloads.*.media_urls' => ['nullable', 'array'],
            'platform_payloads.*.media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'platform_payloads.*.media_ids' => ['nullable', 'array'],
            'platform_payloads.*.media_ids.*' => ['nullable', 'integer'],
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
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ], $this->youtubeValidationRules()));

        // Ensure every requested account belongs to this workspace (cross-workspace IDOR guard).
        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $accounts = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->get(['id', 'network']);
        if ($accounts->count() !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'target_accounts' => ['One or more selected accounts do not belong to your workspace.'],
            ]);
        }

        $selectedNetworks = $accounts->pluck('network')->unique();
        $this->validatePlatformPayloads($selectedNetworks, $validated, $accounts);
        $this->validateUploadedMediaCompatibility($selectedNetworks, $validated, $request);
        $mediaUrls = array_values(array_filter($validated['media_urls'] ?? [], fn ($value) => $value !== null && $value !== ''));
        $validated['media_urls'] = $mediaUrls;

        // scheduled_at arrives as UTC ISO from the frontend (already converted).
        // Allow a 30-second buffer to account for form submission latency.
        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        $allMediaIds = collect($validated['media_ids'] ?? [])
            ->push(data_get($validated, 'youtube_options.thumbnail_media_id'))
            ->merge(collect($validated['platform_payloads'] ?? [])->flatMap(fn ($payload) => $payload['media_ids'] ?? []))
            ->merge(collect($validated['platform_payloads'] ?? [])->pluck('options.thumbnail_media_id'))
            ->filter()->unique()->values()->all();
        unset($validated['media_ids']);

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $post = DB::transaction(function () use ($validated, $wid, $scheduledAt, $request, $allMediaIds): SocialPost {
            $post = SocialPost::create(array_merge($validated, [
                'workspace_id' => $wid,
                'status' => $scheduledAt ? 'scheduled' : 'draft',
            ]));
            $this->mediaLifecycle->syncPostMedia($post, $request->user(), $allMediaIds);

            return $post;
        });

        if (! $scheduledAt) {
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            $post->update(['status' => 'publishing']);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'post_id' => $post->id]);
        }

        return back()->with('success', 'Post '.($scheduledAt ? 'scheduled' : 'queued for publishing').'.');
    }

    public function edit(Request $request, SocialPost $post): Response
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if(in_array($post->status, ['publishing', 'published']), 403, 'Cannot edit a post that is already published.');

        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->where('active', true)->get(['id', 'network', 'name', 'picture_url']);
        $post->setAttribute('media_ids', $post->media()->pluck('media.id')->all());

        return Inertia::render('Social/Posts/Edit', [
            'post' => $post,
            'accounts' => $accounts,
            'storageUsage' => $this->mediaService->usage($request->user()),
        ]);
    }

    public function update(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if(in_array($post->status, ['publishing', 'published']), 403, 'Cannot edit a post that is already published or being published.');

        $validated = $request->validate(array_merge([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['nullable', 'string', 'max:63206'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['nullable', 'integer'],
            'platform_payloads' => ['nullable', 'array'],
            'platform_payloads.*.customize' => ['nullable', 'boolean'],
            'platform_payloads.*.title' => ['nullable', 'string', 'max:256'],
            'platform_payloads.*.body' => ['nullable', 'string', 'max:63206'],
            'platform_payloads.*.media_urls' => ['nullable', 'array'],
            'platform_payloads.*.media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
            'platform_payloads.*.media_ids' => ['nullable', 'array'],
            'platform_payloads.*.media_ids.*' => ['nullable', 'integer'],
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
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'scheduled_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ], $this->youtubeValidationRules()));

        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $accounts = SocialAccount::where('workspace_id', $this->workspaceId($request))
            ->whereIn('id', $requestedIds)
            ->get(['id', 'network']);
        if ($accounts->count() !== $requestedIds->count()) {
            throw ValidationException::withMessages([
                'target_accounts' => ['One or more selected accounts do not belong to your workspace.'],
            ]);
        }

        $selectedNetworks = $accounts->pluck('network')->unique();
        $this->validatePlatformPayloads($selectedNetworks, $validated, $accounts);
        $this->validateUploadedMediaCompatibility($selectedNetworks, $validated, $request);
        $mediaUrls = array_values(array_filter($validated['media_urls'] ?? [], fn ($value) => $value !== null && $value !== ''));
        $validated['media_urls'] = $mediaUrls;

        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        $validated['status'] = ($validated['scheduled_at'] ?? null) ? 'scheduled' : 'draft';

        $mediaIds = collect($validated['media_ids'] ?? [])
            ->push(data_get($validated, 'youtube_options.thumbnail_media_id'))
            ->merge(collect($validated['platform_payloads'] ?? [])->flatMap(fn ($payload) => $payload['media_ids'] ?? []))
            ->merge(collect($validated['platform_payloads'] ?? [])->pluck('options.thumbnail_media_id'))
            ->filter()->unique()->values()->all();
        unset($validated['media_ids']);
        DB::transaction(function () use ($post, $validated, $request, $mediaIds): void {
            $post->update($validated);
            $this->mediaLifecycle->syncPostMedia($post, $request->user(), $mediaIds);
        });

        return redirect()->route('client.social.posts.index')->with('success', 'Post updated successfully.');
    }

    public function publishNow(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'Post is already being published.');
        abort_if($post->status === 'published', 422, 'Post is already published.');

        $post->update(['scheduled_at' => null, 'status' => 'publishing']);
        PublishSocialPostJob::dispatch($post->id)->onQueue('social');

        return back()->with('success', 'Post queued for immediate publishing.');
    }

    public function cancel(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_unless($post->status === 'scheduled', 422, 'Only scheduled posts can be cancelled.');

        $post->update(['status' => 'draft', 'scheduled_at' => null]);

        return back()->with('success', 'Scheduled post cancelled and moved to drafts.');
    }

    public function destroy(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'Cannot delete a post that is currently being published.');
        abort_if(
            $post->status === 'published' || $post->accountLinks()->where('status', 'published')->exists(),
            422,
            'Published posts must be deleted from the connected platform.'
        );
        DB::transaction(function () use ($post): void {
            $this->mediaLifecycle->detachDeletedPost($post);
            $post->accountLinks()->delete();
            $post->delete();
        });

        return back()->with('success', 'Post deleted.');
    }

    public function removeLocalRecord(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'Cannot remove a post that is currently being published.');

        DB::transaction(function () use ($post): void {
            $this->mediaLifecycle->detachDeletedPost($post);
            $post->accountLinks()->delete();
            $post->delete();
        });

        return back()->with('success', 'Post removed from Cerqle. The post on the connected platform was not changed.');
    }

    public function updatePublishedFacebook(
        Request $request,
        SocialPost $post,
        int $account,
        SocialPublisher $publisher
    ): RedirectResponse {
        [$socialAccount, $link] = $this->publishedFacebookTarget($request, $post, $account);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:63206'],
        ]);

        try {
            $publisher->updatePublishedPost($socialAccount, (string) $link->platform_post_id, $validated);
        } catch (\Throwable $e) {
            Log::warning('Facebook published post update failed', [
                'post_id' => $post->id,
                'account_id' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'facebook' => 'Facebook could not update this post. Check the connected Page permissions and try again.',
            ]);
        }

        $results = $post->publish_results ?? [];
        $results[$socialAccount->id] = array_merge($results[$socialAccount->id] ?? [], [
            'status' => 'published',
            'post_id' => $link->platform_post_id,
            'edited_body' => $validated['body'],
            'edited_at' => now()->toIso8601String(),
        ]);

        $updates = ['publish_results' => $results];
        if (count($post->target_accounts ?? []) === 1) {
            $updates['body'] = $validated['body'];
        }
        $post->update($updates);

        return back()->with('success', 'Facebook post updated.');
    }

    public function deletePublishedFacebook(
        Request $request,
        SocialPost $post,
        int $account,
        SocialPublisher $publisher
    ): RedirectResponse {
        [$socialAccount, $link] = $this->publishedFacebookTarget($request, $post, $account);

        try {
            $publisher->deletePublishedPost($socialAccount, (string) $link->platform_post_id);
        } catch (\Throwable $e) {
            Log::warning('Facebook published post deletion failed', [
                'post_id' => $post->id,
                'account_id' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'facebook' => 'Facebook could not delete this post. Check the connected Page permissions and try again.',
            ]);
        }

        DB::transaction(function () use ($post, $socialAccount, $link): void {
            $link->delete();

            $remainingTargets = collect($post->target_accounts ?? [])
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === (int) $socialAccount->id)
                ->values()
                ->all();

            if ($remainingTargets === []) {
                $post->delete();

                return;
            }

            $results = $post->publish_results ?? [];
            unset($results[$socialAccount->id], $results[(string) $socialAccount->id]);

            $hasPublishedTarget = $post->accountLinks()
                ->where('status', 'published')
                ->exists();

            $post->update([
                'target_accounts' => $remainingTargets,
                'publish_results' => $results,
                'provider_post_id' => null,
                'post_url' => null,
                'status' => $hasPublishedTarget ? 'published' : 'failed',
            ]);
        });

        return back()->with('success', 'Facebook post deleted.');
    }

    public function updatePublishedYoutube(
        Request $request,
        SocialPost $post,
        int $account,
        SocialPublisher $publisher
    ): RedirectResponse {
        [$socialAccount, $link] = $this->publishedTarget($request, $post, $account, 'youtube');
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['nullable', 'string', 'max:5000'],
            'youtube_options' => ['required', 'array'],
            'youtube_options.privacy_status' => ['required', 'in:private,unlisted,public'],
            'youtube_options.category_id' => ['required', 'integer', 'between:1,44'],
            'youtube_options.tags' => ['nullable', 'array', 'max:50'],
            'youtube_options.tags.*' => ['string', 'max:60'],
            'youtube_options.made_for_kids' => ['nullable', 'boolean'],
            'youtube_options.contains_synthetic_media' => ['nullable', 'boolean'],
            'youtube_options.default_language' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);

        $tags = array_map(fn ($tag) => trim((string) $tag), $validated['youtube_options']['tags'] ?? []);
        if (mb_strlen(implode(',', $tags)) > 500) {
            throw ValidationException::withMessages([
                'youtube_options.tags' => ['YouTube tags cannot exceed 500 total characters.'],
            ]);
        }
        $validated['youtube_options']['tags'] = array_values(array_filter($tags));

        try {
            $publisher->updatePublishedPost($socialAccount, (string) $link->platform_post_id, $validated);
        } catch (\Throwable $e) {
            Log::warning('YouTube published video update failed', [
                'post_id' => $post->id,
                'account_id' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'youtube' => 'YouTube could not update this video. '.$this->publicProviderError($e->getMessage()),
            ]);
        }

        $results = $post->publish_results ?? [];
        $results[$socialAccount->id] = array_merge($results[$socialAccount->id] ?? [], [
            'status' => 'published',
            'post_id' => $link->platform_post_id,
            'edited_title' => $validated['title'],
            'edited_body' => $validated['body'] ?? '',
            'edited_youtube_options' => $validated['youtube_options'],
            'edited_at' => now()->toIso8601String(),
        ]);

        $updates = ['publish_results' => $results];
        if (count($post->target_accounts ?? []) === 1) {
            $updates = array_merge($updates, [
                'title' => $validated['title'],
                'body' => $validated['body'] ?? '',
                'youtube_options' => array_merge($post->youtube_options ?? [], $validated['youtube_options']),
            ]);
        }
        $post->update($updates);

        return back()->with('success', 'YouTube video updated on YouTube and Cerqle.');
    }

    public function deletePublishedYoutube(
        Request $request,
        SocialPost $post,
        int $account,
        SocialPublisher $publisher
    ): RedirectResponse {
        [$socialAccount, $link] = $this->publishedTarget($request, $post, $account, 'youtube');

        try {
            $publisher->deletePublishedPost($socialAccount, (string) $link->platform_post_id);
        } catch (\Throwable $e) {
            Log::error('YouTube published video deletion failed', [
                'post_id' => $post->id,
                'account_id' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'youtube' => 'YouTube could not delete this video. Nothing was removed from Cerqle. '.$this->publicProviderError($e->getMessage()),
            ]);
        }

        $this->removePublishedTarget($post, $socialAccount, $link);

        return back()->with('success', 'Video permanently deleted from YouTube and Cerqle.');
    }

    public function deletePublishedInstagram(
        Request $request,
        SocialPost $post,
        int $account,
        SocialPublisher $publisher
    ): RedirectResponse {
        [$socialAccount, $link] = $this->publishedTarget($request, $post, $account, 'instagram');

        try {
            $publisher->deletePublishedPost($socialAccount, (string) $link->platform_post_id);
        } catch (\Throwable $e) {
            Log::error('Instagram published post deletion failed', [
                'post_id' => $post->id,
                'account_id' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            $message = str_contains($e->getMessage(), 'Meta code 10')
                ? 'Meta denied deletion because this Instagram connection does not have instagram_manage_contents access. Enable that permission for the Meta app, reconnect this Instagram account, and retry.'
                : 'Instagram could not delete this post. '.$this->publicProviderError($e->getMessage());

            return back()->withErrors(['instagram' => $message]);
        }

        $this->removePublishedTarget($post, $socialAccount, $link);

        return back()->with('success', 'Post deleted from Instagram and Cerqle.');
    }

    private function publicProviderError(string $message): string
    {
        $clean = preg_replace('/(?:access[_ -]?token|token)\s*[=:]\s*[^\s,]+/i', 'token=[redacted]', $message);

        return mb_substr((string) $clean, 0, 260);
    }

    /** @return array{SocialAccount, SocialPostAccount} */
    private function publishedFacebookTarget(Request $request, SocialPost $post, int $accountId): array
    {
        return $this->publishedTarget($request, $post, $accountId, 'facebook');
    }

    /** @return array{SocialAccount, SocialPostAccount} */
    private function publishedTarget(
        Request $request,
        SocialPost $post,
        int $accountId,
        string $network
    ): array {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if($post->status === 'publishing', 422, 'This post is still being published.');

        $account = SocialAccount::query()
            ->where('workspace_id', $this->workspaceId($request))
            ->where('network', $network)
            ->findOrFail($accountId);

        abort_unless(collect($post->target_accounts ?? [])->map(fn ($id) => (int) $id)->contains($account->id), 404);

        $link = SocialPostAccount::query()
            ->where('post_id', $post->id)
            ->where('social_account_id', $account->id)
            ->firstOrFail();

        abort_unless(
            $link->status === 'published' && filled($link->platform_post_id),
            422,
            'This '.ucfirst($network).' post has not been published successfully.'
        );

        return [$account, $link];
    }

    private function removePublishedTarget(
        SocialPost $post,
        SocialAccount $socialAccount,
        SocialPostAccount $link
    ): void {
        DB::transaction(function () use ($post, $socialAccount, $link): void {
            $link->delete();

            $remainingTargets = collect($post->target_accounts ?? [])
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === (int) $socialAccount->id)
                ->values()
                ->all();

            if ($remainingTargets === []) {
                $post->delete();

                return;
            }

            $results = $post->publish_results ?? [];
            unset($results[$socialAccount->id], $results[(string) $socialAccount->id]);

            $post->update([
                'target_accounts' => $remainingTargets,
                'publish_results' => $results,
                'provider_post_id' => null,
                'post_url' => null,
                'status' => $post->accountLinks()->where('status', 'published')->exists() ? 'published' : 'failed',
            ]);
        });
    }

    public function aiPlan(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'campaign_goal' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'in:professional,casual,humorous,inspirational,educational'],
            'post_count' => ['nullable', 'integer', 'min:3', 'max:14'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'target_accounts' => ['required', 'array', 'min:1'],
            'target_accounts.*' => ['integer'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $requestedIds = collect($validated['target_accounts'])->map(fn ($id) => (int) $id);
        $accounts = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $requestedIds)
            ->where('active', true)
            ->get(['id', 'network', 'name']);

        if ($accounts->count() !== $requestedIds->count()) {
            return response()->json(['errors' => ['target_accounts' => ['One or more selected accounts are invalid.']]], 403);
        }

        if ($accounts->contains('network', 'youtube')) {
            throw ValidationException::withMessages([
                'target_accounts' => ['The AI planner creates text-only drafts and cannot supply a YouTube video. Use Post Composer to upload and automate a YouTube video.'],
            ]);
        }

        $networks = $accounts->pluck('network')->unique()->values()->all();
        $postCount = $validated['post_count'] ?? 7;
        $tone = $validated['tone'] ?? 'professional';
        $goal = $validated['campaign_goal'] ?? 'increase engagement and brand awareness';

        try {
            $gateway = app(LlmGateway::class);
            $messages = $this->buildPlanMessages(
                $validated['topic'], $networks, $postCount, $tone, $goal,
                $validated['start_date'], $validated['end_date'], $validated['timezone'] ?? 'UTC'
            );
            $response = $gateway->chat($wid, $messages, [
                'temperature' => 0.7,
                'max_tokens' => 4096,
                'feature_key' => 'social_plan_generate',
                'idempotency_key' => $request->header('Idempotency-Key'),
            ]);
            try {
                $posts = $this->parsePlanResponse($response->content, $postCount);
            } catch (\Throwable $exception) {
                $gateway->rejectMalformed($response);
                throw $exception;
            }

            return response()->json(['posts' => $posts, 'accounts' => $accounts]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e instanceof AiCreditsExhaustedException ? 'ai_credits_exhausted' : 'ai_generation_failed',
            ], $e instanceof AiCreditsExhaustedException ? 402 : 422);
        }
    }

    private function buildPlanMessages(
        string $topic,
        array $networks,
        int $count,
        string $tone,
        string $goal,
        string $startDate,
        string $endDate,
        string $timezone
    ): array {
        $networksStr = implode(', ', $networks);
        $limits = ['tiktok' => 2200, 'linkedin' => 3000, 'facebook' => 63206, 'instagram' => 2200, 'youtube' => 5000];
        $limitLines = collect($networks)->map(fn ($n) => "- {$n}: ".($limits[$n] ?? 5000).' characters')->implode("\n");

        $system = <<<SYSTEM
You are an expert social media strategist. Generate a content calendar as JSON.

RULES:
1. Output ONLY valid JSON — no markdown, no prose, no code fences.
2. Top-level object must be: {"posts": [...]}
3. Generate exactly {$count} posts spread evenly between {$startDate} and {$endDate}.
4. Each post must have EXACTLY these fields:
   - "title": short title (string, max 100 chars)
   - "body": post content (string)
   - "suggested_time": UTC ISO 8601 datetime (e.g. "2026-06-01T10:00:00Z")
   - "rationale": one sentence explaining timing/approach (string)
   - "platform_notes": object keyed by network with tailored copy variants, or null
5. Character limits per network:
{$limitLines}
6. Primary "body" must fit the SHORTEST character limit among: {$networksStr}
7. Tone: {$tone}. Campaign goal: {$goal}.
8. If you cannot produce valid JSON, return exactly: {"error": "generation_failed"}
SYSTEM;

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Create a {$count}-post campaign calendar for: {$topic}\nPlatforms: {$networksStr}\nSchedule: {$startDate} to {$endDate} ({$timezone})."],
        ];
    }

    private function parsePlanResponse(string $content, int $expectedCount): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['posts'])) {
            throw new \RuntimeException('AI returned malformed JSON. Please try again.');
        }
        if (isset($decoded['error'])) {
            throw new \RuntimeException('AI failed to generate the plan. Please refine your brief.');
        }

        return collect($decoded['posts'])->map(function ($post, $i) {
            if (empty($post['body'])) {
                throw new \RuntimeException("Post #{$i} is missing body content.");
            }

            return [
                'title' => $post['title'] ?? '',
                'body' => $post['body'],
                'suggested_time' => $post['suggested_time'] ?? null,
                'rationale' => $post['rationale'] ?? '',
                'platform_notes' => $post['platform_notes'] ?? null,
            ];
        })->all();
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'posts' => ['required', 'array', 'min:1', 'max:14'],
            'posts.*.title' => ['nullable', 'string', 'max:256'],
            'posts.*.body' => ['required', 'string', 'max:5000'],
            'posts.*.scheduled_at' => ['nullable', 'date'],
            'posts.*.timezone' => ['nullable', 'string', 'max:64'],
            'posts.*.target_accounts' => ['required', 'array', 'min:1'],
            'posts.*.target_accounts.*' => ['integer'],
            'posts.*.ai_prompt' => ['nullable', 'string', 'max:1000'],
        ]);

        $allIds = collect($validated['posts'])
            ->flatMap(fn ($p) => $p['target_accounts'])
            ->map(fn ($id) => (int) $id)
            ->unique();

        $ownedCount = SocialAccount::where('workspace_id', $wid)->whereIn('id', $allIds)->count();
        if ($ownedCount !== $allIds->count()) {
            return response()->json(['errors' => ['posts' => ['One or more accounts do not belong to your workspace.']]], 403);
        }

        $containsYoutube = SocialAccount::where('workspace_id', $wid)
            ->whereIn('id', $allIds)
            ->where('network', 'youtube')
            ->exists();
        if ($containsYoutube) {
            throw ValidationException::withMessages([
                'posts' => ['Bulk AI drafts do not include video files. Use Post Composer for YouTube uploads.'],
            ]);
        }

        $now = now();
        foreach ($validated['posts'] as $i => $postData) {
            if (! empty($postData['scheduled_at']) && $now->copy()->addMinute()->gt($postData['scheduled_at'])) {
                return response()->json(['errors' => ["posts.{$i}.scheduled_at" => ['Must be at least 1 minute in the future.']]], 422);
            }
        }

        $created = [];
        \DB::transaction(function () use ($validated, $wid, &$created) {
            foreach ($validated['posts'] as $postData) {
                $scheduledAt = $postData['scheduled_at'] ?? null;
                $post = SocialPost::create([
                    'workspace_id' => $wid,
                    'title' => $postData['title'] ?? null,
                    'body' => $postData['body'],
                    'media_urls' => [],
                    'target_accounts' => array_map('intval', $postData['target_accounts']),
                    'scheduled_at' => $scheduledAt,
                    'timezone' => $postData['timezone'] ?? 'UTC',
                    'status' => $scheduledAt ? 'scheduled' : 'draft',
                    'ai_generated' => true,
                    'ai_prompt' => $postData['ai_prompt'] ?? null,
                ]);
                $created[] = $post->id;
            }
        });

        return response()->json(['success' => true, 'created' => count($created), 'post_ids' => $created]);
    }

    /** AI Post Planner – generate body copy from a prompt. */
    public function aiGenerate(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);
        $request->validate([
            'prompt' => ['required', 'string', 'max:500'],
            'network' => ['nullable', 'string'],
        ]);

        try {
            $gateway = app(LlmGateway::class);
            $network = $request->network ?? 'any social network';
            $messages = [
                ['role' => 'system', 'content' => "You are a social media copywriter. Write engaging, concise posts optimized for {$network}. Return ONLY the post text, no explanations."],
                ['role' => 'user',   'content' => $request->prompt],
            ];
            $response = $gateway->chat($wid, $messages, [
                'feature_key' => 'social_single_generate',
                'idempotency_key' => $request->header('Idempotency-Key'),
            ]);

            if (blank($response->content)) {
                $gateway->rejectMalformed($response);
                throw new \RuntimeException('AI returned an empty post. Please try again.');
            }

            return response()->json(['body' => $response->content]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e instanceof AiCreditsExhaustedException ? 'ai_credits_exhausted' : 'ai_generation_failed',
            ], $e instanceof AiCreditsExhaustedException ? 402 : 422);
        }
    }
}
