<?php

namespace App\Modules\Inbox\Jobs;

use App\Modules\Inbox\Services\MessengerDriver;
use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMessengerAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $maxExceptions = 3;

    public int $timeout = 180;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $channelAccountId) {}

    public function uniqueId(): string
    {
        return (string) $this->channelAccountId;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('messenger-account-sync:'.$this->channelAccountId))
                ->releaseAfter(10)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function backoff(): array
    {
        return [30, 60, 180, 300];
    }

    public function handle(MessengerDriver $messenger): void
    {
        $account = ChannelAccount::where('channel', 'messenger')
            ->where('status', 'active')
            ->find($this->channelAccountId);

        if (! $account) {
            return;
        }

        try {
            $messenger->syncRecentMessages($account);
        } catch (Throwable $e) {
            $meta = $account->meta_json ?? [];
            $account->update([
                'meta_json' => array_merge($meta, ['messenger_last_sync_error' => $e->getMessage()]),
            ]);
            Log::warning('Messenger reconciliation failed', [
                'channel_account_id' => $account->id,
                'workspace_id' => $account->workspace_id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
