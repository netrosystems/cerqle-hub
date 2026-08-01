<?php

namespace App\Modules\Inbox\Jobs;

use App\Events\MessageReceived;
use App\Modules\Inbox\Services\GenericMailboxClient;
use App\Modules\Inbox\Services\GoogleGmailClient;
use App\Modules\Inbox\Services\MicrosoftGraphMailClient;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncEmailAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->channelAccountId;
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(public readonly int $channelAccountId) {}

    public function handle(MicrosoftGraphMailClient $microsoft, GoogleGmailClient $google, GenericMailboxClient $generic): void
    {
        $account = ChannelAccount::where('channel', 'email')->where('status', 'active')->find($this->channelAccountId);
        if (! $account) {
            return;
        }

        try {
            $items = match ($account->provider) {
                'microsoft_365' => $microsoft->syncInbox($account),
                'gmail' => $google->syncInbox($account),
                default => $generic->messages($account),
            };
            foreach ($items as $item) {
                $this->ingest($account, $item);
            }
        } catch (Throwable $e) {
            $meta = $account->meta_json ?? [];
            // Keep the account active so the queue retry and next scheduled poll
            // can recover from a temporary provider/network failure.
            $account->update(['meta_json' => array_merge($meta, ['last_sync_error' => $e->getMessage()])]);
            Log::warning('Email mailbox sync failed', ['channel_account_id' => $account->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function ingest(ChannelAccount $account, array $item): void
    {
        $providerId = trim((string) ($item['id'] ?? ''));
        if ($providerId === '' || Message::where('channel', 'email')
            ->where('provider_message_id', $providerId)
            ->whereHas('conversation', fn ($query) => $query->where('channel_account_id', $account->id))
            ->exists()) {
            return;
        }
        $address = strtolower(trim((string) data_get($item, 'from.emailAddress.address')));
        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $name = trim((string) data_get($item, 'from.emailAddress.name'));
        [$first, $last] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, null);
        $contact = Contact::firstOrCreate(
            ['workspace_id' => $account->workspace_id, 'email' => $address],
            [
                'first_name' => $first ?: $address,
                'last_name' => $last,
                'source' => 'email',
                'opt_in_email' => false,
                'opt_in_sms' => false,
                'opt_in_whatsapp' => false,
            ],
        );
        $thread = (string) ($item['conversationId'] ?? $item['internetMessageId'] ?? $providerId);
        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $account->workspace_id,
                'channel_account_id' => $account->id,
                'contact_id' => $contact->id,
                'external_thread_id' => substr($thread, 0, 128),
            ],
            ['status' => 'open', 'assigned_to' => 'human'],
        );
        $body = trim(strip_tags((string) data_get($item, 'body.content', $item['bodyPreview'] ?? '')));
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => $body,
            'payload' => [
                'subject' => (string) ($item['subject'] ?? '(no subject)'),
                'internet_message_id' => (string) ($item['internetMessageId'] ?? ''),
                'provider_thread_id' => (string) ($item['conversationId'] ?? ''),
                'has_attachments' => (bool) ($item['hasAttachments'] ?? false),
            ],
            'status' => 'delivered',
            'provider_message_id' => $providerId,
            'sent_by' => 'human',
            'sent_at' => $item['receivedDateTime'] ?? now(),
        ]);
        $conversation->update([
            'last_message_at' => $message->sent_at,
            'last_inbound_at' => $message->sent_at,
            'status' => $conversation->status === 'resolved' ? 'open' : $conversation->status,
            'unread_count' => $conversation->unread_count + 1,
        ]);
        MessageReceived::dispatch($message);
    }
}
