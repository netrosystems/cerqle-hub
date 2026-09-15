<?php

namespace App\Modules\Social\Services;

use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\XPublishAttempt;
use App\Modules\Social\Services\Drivers\XDriver;
use App\Services\ClientAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class XDestinationPublisher
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function advance(SocialPost $post, SocialAccount $account, array $payload): array
    {
        return Cache::lock('x-publish:'.$post->id.':'.$account->id, 1900)->block(2, function () use ($post, $account, $payload): array {
            $attempt = XPublishAttempt::firstOrCreate([
                'post_id' => $post->id, 'social_account_id' => $account->id,
            ], ['workspace_id' => $post->workspace_id, 'payload' => array_merge($payload, ['status' => 'publishing'])]);
            if ($attempt->status === 'creating') {
                $attempt->update(['status' => 'unknown', 'error' => 'X may have accepted this post. Review before retrying.']);
            }
            if (in_array($attempt->status, ['published', 'unknown', 'failed'], true)) {
                return $this->result($attempt);
            }
            if ($attempt->retry_at?->isFuture()) {
                $this->schedule($post, max(1, now()->diffInSeconds($attempt->retry_at)));

                return $this->result($attempt);
            }
            if ($attempt->status === 'pending' && $attempt->payload === []) {
                $attempt->update(['payload' => array_merge($payload, ['status' => 'publishing'])]);
            }
            try {
                $this->eligible($post, $account);
                $account = app(SocialAccessTokenService::class)->fresh($account->fresh());
                if (($attempt->payload['x_oauth_client_id'] ?? data_get($account->meta, 'oauth_client_id')) !== data_get($account->meta, 'oauth_client_id')) {
                    throw new \RuntimeException('X application changed during publishing.');
                }
                // Probe actual files at entry and before create, not on every 4 MB chunk.
                if ($attempt->status === 'pending' || empty($attempt->media_state)) {
                    app(XContentValidator::class)->assertPayload($attempt->payload, $post->workspace_id);
                }
                $ids = (array) ($attempt->payload['media_ids'] ?? []);
                $state = app(XMediaUploader::class)->advance($account, $ids, (array) $attempt->media_state);
                $attempt->update(['media_state' => $state, 'status' => $state['stage'] ?? 'uploading', 'retry_at' => null]);
                if (($state['stage'] ?? '') !== 'ready') {
                    $this->schedule($post, (int) ($state['retry_after'] ?? 5));

                    return $this->result($attempt);
                }
                $this->eligible($post, $account);
                $account = app(SocialAccessTokenService::class)->fresh($account->fresh());
                app(XContentValidator::class)->assertPayload($attempt->payload, $post->workspace_id);
                if (($attempt->payload['x_oauth_client_id'] ?? data_get($account->meta, 'oauth_client_id')) !== data_get($account->meta, 'oauth_client_id')) {
                    throw new \RuntimeException('X application changed during publishing.');
                }
                // Inspection/refresh can cross expiry. Never create with an expired upload.
                $state = app(XMediaUploader::class)->advance($account, $ids, $state);
                $attempt->update(['media_state' => $state, 'status' => $state['stage']]);
                if ($state['stage'] !== 'ready') {
                    $this->schedule($post, (int) ($state['retry_after'] ?? 5));

                    return $this->result($attempt);
                }
                // Durable boundary: a crash after this point must not blindly create again.
                $attempt->update(['status' => 'creating']);
                $id = (new XDriver)->publish($account, array_merge($attempt->payload, ['x_media_ids' => $state['media_ids'] ?? []]));
                $attempt->update(['status' => 'published', 'platform_post_id' => $id, 'error' => null]);
            } catch (XProviderException $e) {
                if ($e->category === 'rate_limit' && $attempt->retry_count < 3) {
                    $seconds = max(5, min(86400, $e->retryAfter ?? 60));
                    $attempt->update(['status' => 'pending', 'retry_count' => $attempt->retry_count + 1, 'retry_at' => now()->addSeconds($seconds), 'error' => $e->getMessage()]);
                    $this->schedule($post, $seconds);
                } else {
                    $attempt->update(['status' => $e->category === 'unknown' || $e->outcome === 'unknown' ? 'unknown' : 'failed', 'error' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                $attempt->update([
                    'status' => $attempt->status === 'creating' ? 'unknown' : 'failed',
                    'error' => $e instanceof ValidationException ? collect($e->errors())->flatten()->first()
                        : ($attempt->status === 'creating' ? 'X outcome unknown. Review before retrying.' : 'X publishing is unavailable. Check connection, media and subscription.'),
                ]);
            }

            return $this->result($attempt->fresh());
        });
    }

    private function eligible(SocialPost $post, SocialAccount $account): void
    {
        $current = $post->fresh();
        if (! $current || $current->status !== 'publishing'
            || ! in_array($account->id, array_map('intval', (array) $current->target_accounts), true)
            || (int) $account->workspace_id !== (int) $current->workspace_id
            || ! SocialAccount::whereKey($account->id)->where('workspace_id', $current->workspace_id)->where('active', true)->exists()
            || ! app(ClientAccessService::class)->allowsWorkspaceWrite($current->workspace_id)) {
            throw new \RuntimeException('X destination is no longer eligible.');
        }
    }

    private function schedule(SocialPost $post, int|float $seconds): void
    {
        PublishSocialPostJob::dispatch($post->id)->delay(now()->addSeconds(max(1, (int) $seconds)))->onQueue('social');
    }

    /** @return array<string, mixed> */
    private function result(XPublishAttempt $attempt): array
    {
        $status = in_array($attempt->status, ['published', 'unknown', 'failed'], true) ? $attempt->status
            : ($attempt->status === 'processing' ? 'processing' : 'uploading');

        return array_filter([
            'status' => $status, 'post_id' => $attempt->platform_post_id, 'error' => $attempt->error,
            'url' => $attempt->platform_post_id ? 'https://x.com/i/web/status/'.$attempt->platform_post_id : null,
        ], fn ($value) => $value !== null);
    }
}
