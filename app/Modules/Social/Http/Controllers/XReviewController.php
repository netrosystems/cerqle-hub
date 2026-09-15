<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\XPublishAttempt;
use App\Modules\Social\Services\SocialAccessTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class XReviewController extends Controller
{
    public function review(Request $request, SocialPost $post, SocialAccount $account): RedirectResponse
    {
        $wid = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        abort_unless((int) $post->workspace_id === $wid && (int) $account->workspace_id === $wid && $account->network === 'twitter', 403);
        abort_unless(in_array($account->id, array_map('intval', (array) $post->target_accounts), true), 422);
        $data = $request->validate([
            'action' => ['required', 'in:record,retry'],
            'platform_post_id' => ['required_if:action,record', 'nullable', 'regex:/^[0-9]{1,19}$/'],
            'confirm_duplicate_risk' => ['exclude_unless:action,retry', 'required', 'accepted'],
        ]);

        Cache::lock('x-publish:'.$post->id.':'.$account->id, 60)->block(2, function () use ($post, $account, $data, $request): void {
            $attempt = XPublishAttempt::where('post_id', $post->id)->where('social_account_id', $account->id)
                ->where('workspace_id', $post->workspace_id)->firstOrFail();
            abort_unless(in_array($attempt->status, ['unknown', 'failed'], true), 422, 'This destination does not need review.');
            $history = (array) $attempt->review_history;
            $history[] = ['action' => $data['action'], 'user_id' => $request->user()->id, 'at' => now()->toIso8601String(),
                'status' => $attempt->status, 'payload' => $attempt->payload, 'media_state' => $attempt->media_state, 'error' => $attempt->error];
            if ($data['action'] === 'record') {
                $account = app(SocialAccessTokenService::class)->fresh($account);
                $response = Http::withToken($account->access_token)->timeout(15)
                    ->get('https://api.x.com/2/tweets/'.$data['platform_post_id'], ['tweet.fields' => 'author_id']);
                abort_unless($response->successful() && (string) $response->json('data.author_id') === (string) $account->account_id, 422, 'The post could not be verified as belonging to this X account.');
                $attempt->update(['status' => 'published', 'platform_post_id' => $data['platform_post_id'], 'error' => null, 'review_history' => $history]);
            } else {
                $attempt->update(['status' => 'pending', 'payload' => [], 'media_state' => [], 'retry_count' => 0, 'retry_at' => null, 'error' => null, 'review_history' => $history]);
            }
            $post->update(['status' => 'publishing']);
            PublishSocialPostJob::dispatch($post->id)->onQueue('social');
        });

        return back()->with('success', 'X review saved. Publishing status will refresh.');
    }
}
