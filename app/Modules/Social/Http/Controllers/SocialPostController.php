<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\SocialPublisher;
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

    private function validateYoutubeOptions(Collection $selectedNetworks, array $validated, array $mediaUrls): void
    {
        if (! $selectedNetworks->contains('youtube')) {
            return;
        }

        $errors = [];
        $title = trim((string) ($validated['title'] ?? ''));

        if ($title === '') {
            $errors['title'] = ['A YouTube video title is required.'];
        } elseif (mb_strlen($title) > 100) {
            $errors['title'] = ['YouTube video titles cannot exceed 100 characters.'];
        }

        if (count($mediaUrls) !== 1) {
            $errors['media_urls'] = ['YouTube requires exactly one video file. Remove extra media items.'];
        }

        $tags = array_map(fn ($tag) => trim((string) $tag), $validated['youtube_options']['tags'] ?? []);
        if (mb_strlen(implode(',', $tags)) > 500) {
            $errors['youtube_options.tags'] = ['YouTube tags cannot exceed 500 total characters.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateContentRequirements(Collection $selectedNetworks, array $validated): void
    {
        $isYoutubeOnly = $selectedNetworks->count() === 1 && $selectedNetworks->contains('youtube');
        if (! $isYoutubeOnly && trim((string) ($validated['body'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'body' => ['Post content is required for the selected social networks.'],
            ]);
        }
    }

    private function youtubeValidationRules(): array
    {
        return [
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
        ];
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $status = $request->query('status');
        $network = $request->query('network');

        $accounts = SocialAccount::where('workspace_id', $wid)
            ->where('active', true)
            ->get(['id', 'network', 'name', 'picture_url']);

        // Collect account IDs for the requested network filter
        $networkAccountIds = $network
            ? $accounts->where('network', $network)->pluck('id')->map(fn ($id) => (string) $id)
            : collect();

        $query = SocialPost::where('workspace_id', $wid)
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

        return Inertia::render('Social/Composer', ['accounts' => $accounts]);
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
            'body' => ['nullable', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
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
        $this->validateContentRequirements($selectedNetworks, $validated);
        $mediaUrls = array_values(array_filter($validated['media_urls'] ?? [], fn ($value) => $value !== null && $value !== ''));
        $validated['media_urls'] = $mediaUrls;
        if ($selectedNetworks->contains('instagram') && count($mediaUrls) === 0) {
            throw ValidationException::withMessages([
                'media_urls' => ['Instagram publishing requires at least one publicly reachable image URL.'],
            ]);
        }
        if ($selectedNetworks->intersect(['youtube', 'tiktok'])->isNotEmpty() && count($mediaUrls) === 0) {
            throw ValidationException::withMessages([
                'media_urls' => ['YouTube and TikTok publishing require a publicly reachable video URL.'],
            ]);
        }
        $this->validateDirectVideoUrls($selectedNetworks, $mediaUrls);
        $this->validateYoutubeOptions($selectedNetworks, $validated, $mediaUrls);
        if (! empty($validated['youtube_options']['playlist_id']) && $accounts->where('network', 'youtube')->count() !== 1) {
            throw ValidationException::withMessages([
                'youtube_options.playlist_id' => ['Playlist placement requires exactly one selected YouTube channel.'],
            ]);
        }

        // scheduled_at arrives as UTC ISO from the frontend (already converted).
        // Allow a 30-second buffer to account for form submission latency.
        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        $post = SocialPost::create(array_merge($validated, [
            'workspace_id' => $wid,
            'status' => $validated['scheduled_at'] ? 'scheduled' : 'draft',
        ]));

        if (! $validated['scheduled_at']) {
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            $post->update(['status' => 'publishing']);
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'post_id' => $post->id]);
        }

        return back()->with('success', 'Post '.($validated['scheduled_at'] ? 'scheduled' : 'queued for publishing').'.');
    }

    public function edit(Request $request, SocialPost $post): Response
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if(in_array($post->status, ['publishing', 'published']), 403, 'Cannot edit a post that is already published.');

        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->where('active', true)->get(['id', 'network', 'name', 'picture_url']);

        return Inertia::render('Social/Posts/Edit', [
            'post' => $post,
            'accounts' => $accounts,
        ]);
    }

    public function update(Request $request, SocialPost $post): RedirectResponse
    {
        abort_unless((int) $post->workspace_id === $this->workspaceId($request), 403);
        abort_if(in_array($post->status, ['publishing', 'published']), 403, 'Cannot edit a post that is already published or being published.');

        $validated = $request->validate(array_merge([
            'title' => ['nullable', 'string', 'max:256'],
            'body' => ['nullable', 'string', 'max:5000'],
            'media_urls' => ['nullable', 'array'],
            'media_urls.*' => ['nullable', 'url', 'regex:/^https:\/\//i', 'max:2048'],
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
        $this->validateContentRequirements($selectedNetworks, $validated);
        $mediaUrls = array_values(array_filter($validated['media_urls'] ?? [], fn ($value) => $value !== null && $value !== ''));
        $validated['media_urls'] = $mediaUrls;
        if ($selectedNetworks->contains('instagram') && count($mediaUrls) === 0) {
            throw ValidationException::withMessages([
                'media_urls' => ['Instagram publishing requires at least one publicly reachable image URL.'],
            ]);
        }
        if ($selectedNetworks->intersect(['youtube', 'tiktok'])->isNotEmpty() && count($mediaUrls) === 0) {
            throw ValidationException::withMessages([
                'media_urls' => ['YouTube and TikTok publishing require a publicly reachable video URL.'],
            ]);
        }
        $this->validateDirectVideoUrls($selectedNetworks, $mediaUrls);
        $this->validateYoutubeOptions($selectedNetworks, $validated, $mediaUrls);
        if (! empty($validated['youtube_options']['playlist_id']) && $accounts->where('network', 'youtube')->count() !== 1) {
            throw ValidationException::withMessages([
                'youtube_options.playlist_id' => ['Playlist placement requires exactly one selected YouTube channel.'],
            ]);
        }

        if (! empty($validated['scheduled_at']) && now()->subSeconds(30)->gt($validated['scheduled_at'])) {
            throw ValidationException::withMessages([
                'scheduled_at' => ['The scheduled time must be in the future.'],
            ]);
        }

        $validated['status'] = $validated['scheduled_at'] ? 'scheduled' : 'draft';

        $post->update($validated);

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
            $post->accountLinks()->delete();
            $post->delete();
        });

        return back()->with('success', 'Post deleted.');
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

    public function deletePublishedInstagram(
        Request $request,
        SocialPost $post,
        int $account
    ): RedirectResponse {
        [$socialAccount, $link] = $this->publishedTarget(
            $request,
            $post,
            $account,
            'instagram',
            requirePlatformPostId: false,
        );

        $this->removePublishedTarget($post, $socialAccount, $link);

        return back()->with(
            'success',
            'Post removed from Cerqle. Instagram does not provide an API for deleting published media, so the post remains on Instagram until you delete it there.'
        );
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
        string $network,
        bool $requirePlatformPostId = true,
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
            $link->status === 'published' && (! $requirePlatformPostId || filled($link->platform_post_id)),
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
            $response = $gateway->chat($wid, $messages, ['temperature' => 0.7, 'max_tokens' => 4096]);
            $posts = $this->parsePlanResponse($response->content, $postCount);

            return response()->json(['posts' => $posts, 'accounts' => $accounts]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
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
            $response = $gateway->chat($wid, $messages, []);

            return response()->json(['body' => $response->content]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
