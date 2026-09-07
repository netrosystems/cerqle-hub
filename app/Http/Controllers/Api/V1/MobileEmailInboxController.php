<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\MessageSent;
use App\Modules\Inbox\Jobs\SyncEmailAccountJob;
use App\Modules\Inbox\Services\EmailInboxSyncDispatcher;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MobileEmailInboxController extends WorkspaceScopedController
{
    public function __construct(
        private readonly ChannelManager $channelManager,
        private readonly EmailInboxSyncDispatcher $syncDispatcher,
        private readonly AttachmentService $attachmentService,
        private readonly StorageManager $storageManager,
    ) {}

    /** GET /api/v1/mobile/email/accounts */
    public function accounts(Request $request): JsonResponse
    {
        $accounts = ChannelAccount::query()
            ->where('workspace_id', $this->workspaceId($request))
            ->where('channel', 'email')
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get();

        return response()->json([
            'data' => $accounts->map(fn (ChannelAccount $account) => $this->formatAccount($account)),
        ]);
    }

    /** GET /api/v1/mobile/email/threads */
    public function threads(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'folder' => ['nullable', 'in:inbox,unread,sent,resolved,all'],
            'account_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $workspaceId = $this->workspaceId($request);
        $folder = $validated['folder'] ?? 'inbox';
        $accountId = isset($validated['account_id']) ? (int) $validated['account_id'] : null;
        $search = trim((string) ($validated['search'] ?? ''));

        if ($accountId) {
            $this->emailAccount($request, $accountId);
        }

        $queuedSyncs = $this->syncDispatcher->dispatchForWorkspace($workspaceId, $accountId);
        $base = Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('channelAccount', fn ($query) => $query->where('channel', 'email'));
        $accountBase = (clone $base)
            ->when($accountId, fn ($query) => $query->where('channel_account_id', $accountId));

        $query = (clone $accountBase)
            ->with([
                'contact',
                'channelAccount',
                'lastMessage.user:id,name,avatar',
                'latestInboundMessage',
                'assignedUser:id,name,avatar',
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('contact', fn ($contact) => $contact->where(fn ($contact) => $contact
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")))
                        ->orWhereHas('messages', fn ($message) => $message
                            ->where('body', 'like', "%{$search}%")
                            ->orWhere('payload', 'like', "%{$search}%"));
                });
            })
            ->when($folder === 'inbox', fn ($query) => $query->where('status', '!=', 'resolved'))
            ->when($folder === 'unread', fn ($query) => $query->where('unread_count', '>', 0))
            ->when($folder === 'sent', fn ($query) => $query->whereHas('messages', fn ($message) => $message->where('direction', 'out')))
            ->when($folder === 'resolved', fn ($query) => $query->where('status', 'resolved'))
            ->orderByDesc('last_message_at')
            ->paginate($validated['per_page'] ?? 30);

        return response()->json([
            'data' => $query->getCollection()->map(fn (Conversation $conversation) => $this->formatThread($conversation)),
            'meta' => [
                'current_page' => $query->currentPage(),
                'last_page' => $query->lastPage(),
                'per_page' => $query->perPage(),
                'total' => $query->total(),
            ],
            'counts' => [
                'inbox' => (clone $accountBase)->where('status', '!=', 'resolved')->count(),
                'unread' => (clone $accountBase)->where('unread_count', '>', 0)->count(),
                'sent' => (clone $accountBase)->whereHas('messages', fn ($message) => $message->where('direction', 'out'))->count(),
                'resolved' => (clone $accountBase)->where('status', 'resolved')->count(),
                'all' => (clone $accountBase)->count(),
            ],
            'sync' => [
                'queued' => $queuedSyncs > 0,
                'queued_accounts' => $queuedSyncs,
                'poll_after_seconds' => 5,
            ],
        ]);
    }

    /** GET /api/v1/mobile/email/threads/{uuid} */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $conversation = $this->emailConversation($request, $uuid, [
            'contact',
            'channelAccount',
            'lastMessage.user:id,name,avatar',
            'assignedUser:id,name,avatar',
            'latestInboundMessage',
        ]);
        $messages = $conversation->messages()
            ->with('user:id,name,avatar')
            ->orderByDesc('sent_at')
            ->paginate(min(max($request->integer('per_page', 50), 1), 100));

        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        return response()->json([
            'thread' => $this->formatThread($conversation),
            'messages' => $messages->getCollection()->reverse()->values()->map(fn (Message $message) => $this->formatMessage($message)),
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ],
        ]);
    }

    /** GET /api/v1/mobile/email/threads/{uuid}/messages */
    public function messages(Request $request, string $uuid): JsonResponse
    {
        $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $conversation = $this->emailConversation($request, $uuid);
        $this->syncDispatcher->dispatchForWorkspace($conversation->workspace_id, $conversation->channel_account_id);

        $messages = $conversation->messages()
            ->with('user:id,name,avatar')
            ->when($request->integer('after_id'), fn ($query, $afterId) => $query->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($request->integer('limit', 100))
            ->get();

        return response()->json([
            'data' => $messages->map(fn (Message $message) => $this->formatMessage($message)),
            'meta' => [
                'last_id' => $messages->last()?->id ?? $request->integer('after_id', 0),
                'poll_after_seconds' => 3,
            ],
        ]);
    }

    /** POST /api/v1/mobile/email/threads/{uuid}/reply */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'attachment' => [
                'nullable', 'file', 'max:'.AttachmentService::MAX_FILE_KILOBYTES,
                'mimes:'.AttachmentService::ALLOWED_MIMES,
            ],
        ]);

        if (empty($validated['body']) && ! $request->hasFile('attachment')) {
            return response()->json(['error' => 'Message body or attachment is required.'], 422);
        }

        $conversation = $this->emailConversation($request, $uuid, ['channelAccount', 'contact']);

        $payload = [];
        $msgType = 'text';

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $upload = $this->attachmentService->processUpload($file, 'message-media');
            $msgType = $upload['type'];
            $payload = [
                'path' => $upload['path'],
                'preview_url' => $upload['url'],
                'filename' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['size_bytes'],
                'has_attachments' => true,
                'attachments' => [
                    [
                        'name' => $upload['filename'],
                        'url' => $upload['url'],
                        'size' => $upload['size_bytes'],
                        'mime_type' => $upload['mime_type'],
                        'type' => $upload['type'],
                    ],
                ],
            ];
        }

        $bodyText = $validated['body'] ?? ($request->hasFile('attachment') ? $upload['filename'] : '');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => $msgType,
            'body' => $bodyText,
            'payload' => $payload,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $sendError = null;
        try {
            $providerMessageId = $this->channelManager->driver('email')->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $providerMessageId]);
        } catch (\Throwable $exception) {
            $sendError = $exception->getMessage();
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
            Log::error('Mobile email reply failed', [
                'conversation_id' => $conversation->id,
                'error' => $sendError,
            ]);
        }

        $conversation->update(['last_message_at' => now()]);
        if ($conversation->last_inbound_at && ! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }
        $message->load(['conversation', 'user:id,name,avatar']);
        MessageSent::dispatch($message);

        return response()->json([
            'message' => $this->formatMessage($message),
            'error' => $sendError,
        ]);
    }

    /** POST /api/v1/mobile/email/compose */
    public function compose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer'],
            'to' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:998'],
            'body' => ['nullable', 'string', 'max:100000'],
            'cc' => ['nullable'],
            'bcc' => ['nullable'],
            'attachment' => [
                'nullable', 'file', 'max:'.AttachmentService::MAX_FILE_KILOBYTES,
                'mimes:'.AttachmentService::ALLOWED_MIMES,
            ],
        ]);

        if (empty($validated['body']) && ! $request->hasFile('attachment')) {
            return response()->json(['error' => 'Message body or attachment is required.'], 422);
        }

        $workspaceId = $this->workspaceId($request);
        $account = $this->emailAccount($request, (int) $validated['account_id']);

        $recipient = strtolower(trim((string) $validated['to']));
        $cc = $this->parseEmailList($validated['cc'] ?? null);
        $bcc = $this->parseEmailList($validated['bcc'] ?? null);

        $contact = Contact::firstOrCreate(
            ['workspace_id' => $workspaceId, 'email' => $recipient],
            [
                'first_name' => Str::before($recipient, '@'),
                'source' => 'email',
                'opt_in_email' => false,
            ]
        );

        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'external_thread_id' => 'outbound:'.Str::uuid(),
            'status' => 'open',
            'assigned_to' => 'human',
            'assigned_user_id' => $request->user()->id,
            'last_message_at' => now(),
        ]);

        $payload = [
            'subject' => $validated['subject'],
            'to' => $recipient,
            'cc' => $cc,
            'bcc' => $bcc,
        ];
        $msgType = 'text';

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $upload = $this->attachmentService->processUpload($file, 'message-media');
            $msgType = $upload['type'];
            $payload['path'] = $upload['path'];
            $payload['preview_url'] = $upload['url'];
            $payload['filename'] = $upload['filename'];
            $payload['mime_type'] = $upload['mime_type'];
            $payload['file_size'] = $upload['size_bytes'];
            $payload['has_attachments'] = true;
            $payload['attachments'] = [
                [
                    'name' => $upload['filename'],
                    'url' => $upload['url'],
                    'size' => $upload['size_bytes'],
                    'mime_type' => $upload['mime_type'],
                    'type' => $upload['type'],
                ],
            ];
        }

        $bodyText = $validated['body'] ?? ($request->hasFile('attachment') ? $upload['filename'] : '');

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => $msgType,
            'body' => $bodyText,
            'payload' => $payload,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $sendError = null;
        try {
            $providerMessageId = $this->channelManager->driver('email')->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $providerMessageId]);
        } catch (\Throwable $exception) {
            $sendError = $exception->getMessage();
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
            Log::error('Mobile email compose failed', [
                'conversation_id' => $conversation->id,
                'error' => $sendError,
            ]);
        }

        $conversation->update(['last_message_at' => now()]);
        $message->load(['conversation', 'user:id,name,avatar']);
        MessageSent::dispatch($message);

        return response()->json([
            'thread' => $this->formatThread($conversation->fresh(['contact', 'channelAccount', 'lastMessage.user:id,name,avatar', 'assignedUser:id,name,avatar'])),
            'message' => $this->formatMessage($message),
            'error' => $sendError,
        ], 201);
    }

    /** PATCH /api/v1/mobile/email/threads/{uuid}/status */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:open,resolved']]);
        $conversation = $this->emailConversation($request, $uuid);
        $conversation->update([
            'status' => $validated['status'],
            'resolved_at' => $validated['status'] === 'resolved' ? ($conversation->resolved_at ?? now()) : null,
        ]);

        return response()->json([
            'ok' => true,
            'status' => $conversation->status,
            'resolved_at' => $conversation->resolved_at?->toIso8601String(),
        ]);
    }

    /** POST /api/v1/mobile/email/accounts/{account}/sync */
    public function sync(Request $request, int $account): JsonResponse
    {
        $emailAccount = $this->emailAccount($request, $account);
        SyncEmailAccountJob::dispatch($emailAccount->id, true)->onQueue('default');

        return response()->json([
            'queued' => true,
            'account_id' => $emailAccount->id,
            'message' => 'Mailbox refresh queued.',
        ], 202);
    }

    private function emailAccount(Request $request, int $id): ChannelAccount
    {
        return ChannelAccount::query()
            ->where('workspace_id', $this->workspaceId($request))
            ->where('channel', 'email')
            ->where('status', 'active')
            ->findOrFail($id);
    }

    private function emailConversation(Request $request, string $uuid, array $with = []): Conversation
    {
        return Conversation::query()
            ->where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->whereHas('channelAccount', fn ($query) => $query->where('channel', 'email'))
            ->with($with)
            ->firstOrFail();
    }

    private function formatAccount(ChannelAccount $account): array
    {
        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'display_name' => $account->display_name,
            'email' => $account->meta_json['email'] ?? null,
            'last_synced_at' => $account->meta_json['last_synced_at'] ?? null,
            'last_sync_error' => $account->meta_json['last_sync_error'] ?? null,
        ];
    }

    private function formatThread(Conversation $conversation): array
    {
        $subjectMessage = $conversation->latestInboundMessage ?? $conversation->lastMessage;

        return [
            'id' => $conversation->id,
            'uuid' => $conversation->uuid,
            'status' => $conversation->status,
            'subject' => $subjectMessage?->payload['subject'] ?? '(No subject)',
            'preview' => Demo::text($conversation->lastMessage?->body),
            'unread_count' => (int) $conversation->unread_count,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'resolved_at' => $conversation->resolved_at?->toIso8601String(),
            'contact' => $conversation->contact ? [
                'id' => $conversation->contact->id,
                'name' => Demo::name($conversation->contact->full_name),
                'email' => Demo::email($conversation->contact->email),
                'avatar' => Demo::active() ? null : $conversation->contact->avatar_url,
            ] : null,
            'account' => $conversation->channelAccount ? $this->formatAccount($conversation->channelAccount) : null,
            'assigned_user' => $conversation->assignedUser ? [
                'id' => $conversation->assignedUser->id,
                'name' => $conversation->assignedUser->name,
                'avatar' => $conversation->assignedUser->avatarUrl(),
            ] : null,
        ];
    }

    private function formatMessage(Message $message): array
    {
        $payload = $message->payload ?? [];
        $previewUrl = $payload['preview_url'] ?? $payload['url'] ?? $payload['file_url'] ?? null;
        $hasAttachments = (bool) ($payload['has_attachments'] ?? false) || ! empty($previewUrl) || ! empty($payload['attachments']);

        $attachments = $payload['attachments'] ?? [];
        if (empty($attachments) && ! empty($previewUrl)) {
            $attachments = [
                [
                    'name' => $payload['filename'] ?? ($message->body ?: 'attachment'),
                    'url' => $previewUrl,
                    'size' => $payload['file_size'] ?? null,
                    'mime_type' => $payload['mime_type'] ?? null,
                    'type' => $message->type,
                ],
            ];
        }

        return [
            'id' => $message->id,
            'direction' => $message->direction,
            'type' => $message->type,
            'body' => Demo::text($message->body),
            'subject' => $payload['subject'] ?? null,
            'attachment_url' => $previewUrl,
            'has_attachments' => $hasAttachments,
            'attachments' => $attachments,
            'payload' => $payload,
            'status' => $message->status,
            'sent_by' => $message->sent_by,
            'user' => $message->user ? [
                'id' => $message->user->id,
                'name' => $message->user->name,
                'avatar' => $message->user->avatarUrl(),
            ] : null,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    private function parseEmailList(mixed $value): array
    {
        if (is_array($value)) {
            $emails = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $emails = preg_split('/[,;]+/', $value) ?: [];
        } else {
            return [];
        }

        $cleaned = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $cleaned[] = $email;
            }
        }

        return array_values(array_unique($cleaned));
    }
}
