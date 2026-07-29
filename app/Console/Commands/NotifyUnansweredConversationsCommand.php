<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Notifications\PendingCustomerReplyNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NotifyUnansweredConversationsCommand extends Command
{
    protected $signature = 'inbox:notify-unanswered {--minutes=60 : Minutes a customer may wait before administrators are emailed}';

    protected $description = 'Email client administrators when a customer has waited too long for a reply';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);
        $sent = 0;

        Conversation::query()
            ->with(['contact', 'channelAccount', 'workspace.owner'])
            ->whereIn('status', ['open', 'pending'])
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '<=', $cutoff)
            ->where(function ($query) {
                $query->whereNull('pending_reply_notified_at')
                    ->orWhereColumn('pending_reply_notified_at', '<=', 'last_inbound_at');
            })
            ->whereDoesntHave('messages', function ($query) {
                $query->where('direction', 'out')
                    ->where('status', '!=', 'failed')
                    ->whereColumn('messages.sent_at', '>', 'conversations.last_inbound_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use (&$sent): void {
                foreach ($conversations as $conversation) {
                    $message = $this->latestInbound($conversation);
                    $recipients = $this->administratorRecipients($conversation);
                    if (! $message || $recipients->isEmpty() || ! $this->claim($conversation)) {
                        continue;
                    }

                    foreach ($recipients as $recipient) {
                        $recipient->notify(new PendingCustomerReplyNotification($conversation, $message));
                    }

                    $sent++;
                }
            });

        $this->info("Sent pending-reply reminders for {$sent} conversation(s).");

        return self::SUCCESS;
    }

    private function latestInbound(Conversation $conversation): ?Message
    {
        return $conversation->messages()
            ->where('direction', 'in')
            ->latest('sent_at')
            ->first();
    }

    private function claim(Conversation $conversation): bool
    {
        return Conversation::query()
            ->whereKey($conversation->id)
            ->where(function ($query) {
                $query->whereNull('pending_reply_notified_at')
                    ->orWhereColumn('pending_reply_notified_at', '<=', 'last_inbound_at');
            })
            ->whereDoesntHave('messages', function ($query) {
                $query->where('direction', 'out')
                    ->where('status', '!=', 'failed')
                    ->whereColumn('messages.sent_at', '>', 'conversations.last_inbound_at');
            })
            ->update(['pending_reply_notified_at' => now()]) === 1;
    }

    /**
     * @return Collection<int, User>
     */
    private function administratorRecipients(Conversation $conversation): Collection
    {
        $admins = User::query()
            ->where('workspace_id', $conversation->workspace_id)
            ->where('status', User::STATUS_ACTIVE)
            ->where('client_role', User::CLIENT_ROLE_ADMINISTRATOR)
            ->get();

        $owner = $conversation->workspace?->owner;
        if ($owner && $owner->status === User::STATUS_ACTIVE) {
            $admins->push($owner);
        }

        if ($admins->isEmpty()) {
            $fallback = User::query()
                ->where('workspace_id', $conversation->workspace_id)
                ->where('status', User::STATUS_ACTIVE)
                ->first();
            if ($fallback) {
                $admins->push($fallback);
            }
        }

        return $admins->unique('id')->values();
    }
}
