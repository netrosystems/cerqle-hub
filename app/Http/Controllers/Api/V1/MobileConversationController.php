<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\ConversationAssigned;
use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Events\TypingChanged;
use App\Models\User;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Services\WebchatPresence;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\Media\AttachmentService;
use App\Services\StorageManager;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MobileConversationController extends WorkspaceScopedController
{
    public function __construct(
        private ChannelManager $channelManager,
        private StorageManager $storageManager,
        private AttachmentService $attachmentService,
    ) {}

    /**
     * GET /api/v1/mobile/conversations
     * List conversations with full filter support for the agent inbox.
     */
    public function index(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $userId = $request->user()->id;
        $folder = $request->input('folder', 'all');

        $liveSince = app(WebchatPresence::class)->onlineSince();
        $isLiveFolder = $folder === 'live';

        $conversations = Conversation::where('workspace_id', $wsId)
            ->with(['contact', 'channelAccount', 'lastMessage', 'labels', 'assignedUser'])
            ->when($isLiveFolder, fn ($q) => $q
                ->whereHas('channelAccount', fn ($account) => $account->where('channel', 'webchat'))
                ->where('webchat_last_seen_at', '>=', $liveSince))
            ->when(! $isLiveFolder && ! in_array($folder, ['resolved', 'snoozed'], true), fn ($q) => $q->where('status', 'open'))
            ->when($folder === 'mine', fn ($q) => $q->where('assigned_user_id', $userId))
            ->when($folder === 'unassigned', fn ($q) => $q->whereNull('assigned_user_id'))
            ->when($folder === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->when($folder === 'snoozed', fn ($q) => $q->where('status', 'snoozed'))
            ->when($request->channel, fn ($q) => $q->whereHas('channelAccount', fn ($q) => $q->where('channel', $request->channel)))
            ->when($request->account_id, fn ($q) => $q->where('channel_account_id', $request->account_id))
            ->when($request->label_id, fn ($q) => $q->whereHas('labels', fn ($q) => $q->where('inbox_labels.id', $request->label_id)))
            ->when($request->search, function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->whereHas('contact', function ($c) use ($term) {
                    $c->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('phone_e164', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->when($isLiveFolder, fn ($q) => $q->orderByDesc('webchat_last_seen_at'), fn ($q) => $q->orderByDesc('last_message_at'))
            ->paginate(30);

        return response()->json([
            'data' => $conversations->map(fn ($c) => $this->formatConversation($c)),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'total' => $conversations->total(),
                'live_users_count' => $this->liveUsersQuery($wsId, $liveSince)->distinct('contact_id')->count('contact_id'),
            ],
        ]);
    }

    /**
     * GET /api/v1/mobile/conversations/{uuid}
     * Full conversation detail including messages and metadata.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->with(['contact', 'channelAccount', 'labels', 'assignedUser'])
            ->firstOrFail();

        $messages = $conversation->messages()
            ->with('conversation')
            ->orderBy('sent_at')
            ->get();

        $conversation->update(['unread_count' => 0]);

        $conversation->setAttribute(
            'is_whatsapp_window_open',
            $conversation->channelAccount?->channel !== 'whatsapp' || $conversation->isWhatsappWindowOpen(),
        );

        return response()->json([
            'conversation' => $this->formatConversation($conversation, detail: true),
            'messages' => $messages->map(fn ($m) => $this->formatMessage($m)),
            'messages_meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ]);
    }

    /**
     * GET /api/v1/mobile/conversations/{uuid}/messages
     * Paginated message history (for loading older messages).
     */
    public function messages(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $messages = $conversation->messages()
            ->orderBy('sent_at')
            ->get();

        return response()->json([
            'data' => $messages->map(fn ($m) => $this->formatMessage($m)),
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
            ],
        ]);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/reply
     * Send a message (text, template, media).
     */
    public function reply(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->with('channelAccount')
            ->firstOrFail();

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4096'],
            'type' => ['nullable', 'in:text,template,image,document,video,audio'],
            'payload' => ['nullable', 'array'],
            'attachment' => [
                'nullable', 'file', 'max:'.AttachmentService::MAX_FILE_KILOBYTES,
                'mimes:'.AttachmentService::ALLOWED_MIMES,
            ],
        ]);

        $msgType = $validated['type'] ?? 'text';
        $msgPayload = $validated['payload'] ?? null;
        $channel = $conversation->channelAccount?->channel ?? 'whatsapp';

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $upload = $this->attachmentService->processUpload($file, 'message-media');

            if ($msgType === 'text' || empty($msgType)) {
                $msgType = $upload['type'];
            } elseif ($msgType === 'audio' && in_array($upload['type'], ['audio', 'video', 'document'], true)) {
                $msgType = 'audio';
            } else {
                $msgType = $upload['type'];
            }

            if ($channel === 'instagram' && ! in_array($msgType, ['image', 'video', 'audio'], true)) {
                return response()->json([
                    'error' => 'Instagram direct messaging only supports image, video, and audio attachments. Documents cannot be sent via Instagram DM.',
                ], 422);
            }

            $attachmentPayload = [
                'path' => $upload['path'],
                'preview_url' => $upload['url'],
                'caption' => $validated['body'] ?? null,
                'filename' => $upload['filename'],
                'mime_type' => $upload['mime_type'],
                'file_size' => $upload['size_bytes'],
            ];

            if ($channel === 'whatsapp') {
                $client = CloudApiClient::forWorkspace($conversation->workspace_id);
                if (! $client) {
                    return response()->json(['error' => 'No active WhatsApp account.'], 422);
                }

                $tempPath = null;
                if ($upload['is_converted_heic']) {
                    $tempPath = tempnam(sys_get_temp_dir(), 'wa_upload_').'.jpg';
                    file_put_contents($tempPath, $this->storageManager->disk()->get($upload['path']));
                    $uploadPath = $tempPath;
                } else {
                    $uploadPath = $file->getRealPath();
                }

                try {
                    $attachmentPayload['media_id'] = $client->uploadMedia($uploadPath, $upload['mime_type']);
                } finally {
                    if ($tempPath && file_exists($tempPath)) {
                        @unlink($tempPath);
                    }
                }
            }

            $msgPayload = array_merge($msgPayload ?? [], $attachmentPayload);
            $validated['body'] = $validated['body'] ?? ($msgType === 'audio' ? 'Voice message' : $upload['filename']);
        }

        if ($msgType === 'text' && empty($validated['body'])) {
            return response()->json(['error' => 'Message body is required.'], 422);
        }

        if ($conversation->channelAccount?->channel === 'whatsapp'
            && ! $conversation->isWhatsappWindowOpen()
            && $msgType !== 'template') {
            return response()->json([
                'error' => 'WhatsApp 24-hour session is closed. Use an approved template.',
                'window_closed' => true,
            ], 422);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => $conversation->channelAccount?->channel ?? 'whatsapp',
            'type' => $msgType,
            'body' => $validated['body'],
            'payload' => $msgPayload,
            'status' => 'queued',
            'sent_by' => 'human',
            'user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $sendError = null;
        try {
            $driver = $this->channelManager->driver($conversation->channelAccount?->channel ?? 'whatsapp');
            $messageId = $driver->send($message);
            $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
        } catch (\Throwable $e) {
            $sendError = $e->getMessage();
            Log::error('Mobile reply send failed', [
                'conversation_id' => $conversation->id,
                'error' => $sendError,
            ]);
            $message->update(['status' => 'failed', 'error_json' => ['message' => $sendError]]);
        }

        $conversation->update(['last_message_at' => now()]);
        if ($conversation->last_inbound_at && ! $conversation->first_response_at) {
            $conversation->update(['first_response_at' => now()]);
        }

        $message->load('conversation');
        MessageSent::dispatch($message);

        return response()->json([
            'data' => $this->formatMessage($message),
            'message' => $this->formatMessage($message),
            'error' => $sendError,
        ]);
    }

    /**
     * PATCH /api/v1/mobile/conversations/{uuid}/assign
     */
    public function assign(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate(['user_id' => ['nullable', 'integer']]);

        $assignedTo = null;
        if ($request->user_id) {
            $assignedTo = User::inWorkspace($conversation->workspace_id)->find($request->user_id);
            abort_unless($assignedTo, 422, 'User not found in workspace.');
        }

        $conversation->update(['assigned_user_id' => $request->user_id]);
        ConversationAssigned::dispatch($conversation, $assignedTo);

        return response()->json(['ok' => true, 'assigned_user_id' => $request->user_id]);
    }

    /**
     * PATCH /api/v1/mobile/conversations/{uuid}/status
     */
    public function updateStatus(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate(['status' => ['required', 'in:open,pending,resolved,snoozed']]);

        $updates = ['status' => $request->status];
        if ($request->status === 'resolved' && ! $conversation->resolved_at) {
            $updates['resolved_at'] = now();
        }
        $conversation->update($updates);

        return response()->json(['ok' => true, 'status' => $request->status]);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/typing
     */
    public function typing(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate(['is_typing' => ['required', 'boolean']]);
        broadcast(new TypingChanged($conversation, $request->user(), (bool) $request->is_typing))->toOthers();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/handover
     */
    public function handover(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $mode = $request->input('mode', 'human');
        $updates = ['assigned_to' => $mode];
        if ($mode === 'human' && ! $conversation->handover_at) {
            $updates['handover_at'] = now();
        }
        $conversation->update($updates);

        return response()->json(['ok' => true, 'assigned_to' => $mode]);
    }

    /**
     * GET /api/v1/mobile/conversations/{uuid}/notes
     */
    public function notes(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $notes = $conversation->internalNotes()
            ->with('user:id,name,avatar')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $notes->map(fn ($n) => [
                'id' => $n->id,
                'body' => $n->body,
                'user' => $n->user ? ['id' => $n->user->id, 'name' => $n->user->name, 'avatar' => $n->user->avatar] : null,
                'created_at' => $n->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/notes
     */
    public function storeNote(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $validated = $request->validate(['body' => ['required', 'string', 'max:4096']]);

        $note = $conversation->internalNotes()->create([
            'body' => $validated['body'],
            'user_id' => $request->user()->id,
        ]);
        $note->load('user:id,name,avatar');

        return response()->json([
            'id' => $note->id,
            'body' => $note->body,
            'user' => $note->user ? ['id' => $note->user->id, 'name' => $note->user->name] : null,
            'created_at' => $note->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/labels
     */
    public function attachLabel(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $request->validate(['label_id' => ['required', 'integer']]);
        $label = InboxLabel::where('workspace_id', $conversation->workspace_id)
            ->findOrFail($request->label_id);

        $conversation->labels()->syncWithoutDetaching([$label->id]);

        return response()->json(['ok' => true]);
    }

    /**
     * DELETE /api/v1/mobile/conversations/{uuid}/labels/{labelId}
     */
    public function detachLabel(Request $request, string $uuid, int $labelId): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $conversation->labels()->detach($labelId);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/mobile/conversations
     * Start a new conversation.
     */
    public function start(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $validated = $request->validate([
            'contact_id' => ['required', 'integer'],
            'channel_account_id' => ['required', 'integer'],
            'body' => ['nullable', 'string', 'max:4096'],
        ]);

        $contact = Contact::where('workspace_id', $wsId)->findOrFail($validated['contact_id']);
        $channelAccount = ChannelAccount::where('workspace_id', $wsId)
            ->where('status', 'active')
            ->findOrFail($validated['channel_account_id']);

        // Channel reachability and delivery target validation
        match ($channelAccount->channel) {
            'whatsapp', 'sms' => throw_if(
                empty($contact->phone_e164),
                ValidationException::withMessages([
                    'channel_account_id' => 'Contact does not have a valid phone number for messaging.',
                ])
            ),
            'email' => throw_if(
                empty($contact->email),
                ValidationException::withMessages([
                    'channel_account_id' => 'Contact does not have an email address.',
                ])
            ),
            'messenger', 'instagram' => throw_if(
                ! Conversation::where('workspace_id', $wsId)
                    ->where('contact_id', $contact->id)
                    ->where('channel_account_id', $channelAccount->id)
                    ->exists(),
                ValidationException::withMessages([
                    'channel_account_id' => "Outbound conversations on {$channelAccount->channel} cannot be initiated without an existing customer thread.",
                ])
            ),
            'webchat' => throw_if(
                empty($contact->custom_fields['webchat_visitor_id'])
                && ! Conversation::where('workspace_id', $wsId)
                    ->where('contact_id', $contact->id)
                    ->where('channel_account_id', $channelAccount->id)
                    ->exists(),
                ValidationException::withMessages([
                    'channel_account_id' => 'Contact does not have an active website chat session.',
                ])
            ),
            default => null,
        };

        $conversation = Conversation::firstOrCreate(
            [
                'workspace_id' => $wsId,
                'contact_id' => $contact->id,
                'channel_account_id' => $channelAccount->id,
            ],
            ['status' => 'open']
        );

        if (! empty($validated['body'])) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'out',
                'channel' => $channelAccount->channel,
                'type' => 'text',
                'body' => $validated['body'],
                'status' => 'queued',
                'sent_by' => 'human',
                'user_id' => $request->user()->id,
                'sent_at' => now(),
            ]);

            try {
                $driver = $this->channelManager->driver($channelAccount->channel);
                $messageId = $driver->send($message);
                $message->update(['status' => 'sent', 'provider_message_id' => $messageId]);
            } catch (\Throwable $e) {
                Log::error('Mobile start conversation send failed', [
                    'conversation_id' => $conversation->id,
                    'channel' => $channelAccount->channel,
                    'error' => $e->getMessage(),
                ]);
                $message->update(['status' => 'failed', 'error_json' => ['message' => $e->getMessage()]]);
            }

            $conversation->update(['last_message_at' => now()]);
            $message->load('conversation');
            MessageSent::dispatch($message);
        }

        $conversation->load(['contact', 'channelAccount', 'labels']);

        return response()->json([
            'conversation' => $this->formatConversation($conversation),
        ], 201);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/open-widget
     * Prompt a currently-online website visitor's widget to open.
     */
    public function openWidget(Request $request, string $uuid, WebchatPresence $presence): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->with(['channelAccount', 'contact'])
            ->firstOrFail();

        abort_unless($conversation->channelAccount?->channel === 'webchat', 422, 'This action is only available for website visitors.');
        $lastSeen = $conversation->webchat_last_seen_at instanceof Carbon
            ? $conversation->webchat_last_seen_at
            : ($conversation->webchat_last_seen_at ? Carbon::parse($conversation->webchat_last_seen_at) : null);
        abort_unless($lastSeen?->gte($presence->onlineSince()), 409, 'This visitor is no longer online.');

        return response()->json([
            'ok' => true,
            'command' => $presence->requestWidgetOpen($conversation),
        ]);
    }

    /**
     * POST /api/v1/mobile/conversations/{uuid}/read
     * Mark all unread incoming messages as read for this conversation.
     */
    public function markRead(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $conversation->update(['unread_count' => 0]);

        $unreadMessages = $conversation->messages()
            ->where('direction', 'in')
            ->whereIn('status', ['queued', 'sent', 'delivered'])
            ->get();

        if ($unreadMessages->isNotEmpty()) {
            $conversation->messages()
                ->whereIn('id', $unreadMessages->pluck('id'))
                ->update(['status' => 'read']);

            foreach ($unreadMessages as $msg) {
                $msg->status = 'read';
                $msg->setRelation('conversation', $conversation);
                MessageStatusUpdated::dispatch($msg);
            }
        }

        return response()->json(['ok' => true, 'unread_count' => 0]);
    }

    /**
     * PATCH/PUT/POST /api/v1/mobile/conversations/{uuid}/contact
     * Update the contact attached to this conversation.
     */
    public function updateContact(Request $request, string $uuid): JsonResponse
    {
        $conversation = Conversation::where('workspace_id', $this->workspaceId($request))
            ->where('uuid', $uuid)
            ->with('contact')
            ->firstOrFail();

        if (! $conversation->contact) {
            return response()->json(['error' => 'No contact attached to this conversation.'], 404);
        }

        return app(MobileInboxController::class)->updateContact($request, $conversation->contact->id);
    }

    private function liveUsersQuery(int $workspaceId, Carbon $liveSince)
    {
        return Conversation::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('channelAccount', fn ($account) => $account->where('channel', 'webchat'))
            ->where('webchat_last_seen_at', '>=', $liveSince);
    }

    // ─── Private formatters ───────────────────────────────────────────────────

    private function formatConversation(Conversation $c, bool $detail = false): array
    {
        $isWebchat = $c->channelAccount?->channel === 'webchat';
        $lastSeen = $c->webchat_last_seen_at instanceof Carbon
            ? $c->webchat_last_seen_at
            : ($c->webchat_last_seen_at ? Carbon::parse($c->webchat_last_seen_at) : null);
        $isOnline = $isWebchat && $lastSeen !== null && $lastSeen->gte(app(WebchatPresence::class)->onlineSince());

        $data = [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'status' => $c->status,
            'channel' => $c->channelAccount?->channel,
            'channel_account_id' => $c->channel_account_id,
            'unread_count' => (int) $c->unread_count,
            'last_message_at' => $c->last_message_at instanceof Carbon ? $c->last_message_at->toIso8601String() : ($c->last_message_at ? Carbon::parse($c->last_message_at)->toIso8601String() : null),
            'is_online' => $isOnline,
            'webchat_last_seen_at' => $lastSeen?->toIso8601String(),
            'assigned_user_id' => $c->assigned_user_id,
            'assigned_to' => $c->assigned_to,
            'assigned_user' => $c->assignedUser ? [
                'id' => $c->assignedUser->id,
                'name' => $c->assignedUser->name,
                'avatar' => $c->assignedUser->avatar ?? null,
            ] : null,
            'contact' => $c->contact ? [
                'id' => $c->contact->id,
                'name' => Demo::name($c->contact->name),
                'phone' => Demo::phone($c->contact->phone_e164),
                'email' => Demo::email($c->contact->email),
                'avatar' => Demo::active() ? null : $c->contact->avatar_url,
                'location' => [
                    'country' => $c->contact->custom_fields['webchat_country'] ?? null,
                    'country_code' => $c->contact->custom_fields['webchat_country_code'] ?? null,
                    'city' => $c->contact->custom_fields['webchat_city'] ?? null,
                    'region' => $c->contact->custom_fields['webchat_region'] ?? null,
                    'lat' => $c->contact->custom_fields['webchat_lat'] ?? null,
                    'lon' => $c->contact->custom_fields['webchat_lon'] ?? null,
                    'page_title' => $c->contact->custom_fields['webchat_page_title'] ?? null,
                    'page_url' => $c->contact->custom_fields['webchat_page_url'] ?? null,
                    'ip' => $c->contact->custom_fields['webchat_last_ip'] ?? null,
                ],
                'custom_fields' => Demo::active()
                    ? Demo::maskArrayValues($c->contact->custom_fields ?? [])
                    : ($c->contact->custom_fields ?? []),
            ] : null,
            'channel_account' => $c->channelAccount ? [
                'id' => $c->channelAccount->id,
                'channel' => $c->channelAccount->channel,
                'display_name' => $c->channelAccount->display_name,
            ] : null,
            'labels' => $c->labels?->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
                'color' => $l->color,
            ])->values(),
            'last_message' => $c->lastMessage ? $this->formatMessage($c->lastMessage) : null,
        ];

        if ($detail) {
            $data['is_whatsapp_window_open'] = $c->getAttribute('is_whatsapp_window_open') ?? true;
            $data['handover_at'] = $c->handover_at?->toIso8601String();
            $data['resolved_at'] = $c->resolved_at?->toIso8601String();
            $data['created_at'] = $c->created_at->toIso8601String();
        }

        return $data;
    }

    private function formatMessage(Message $m): array
    {
        return [
            'id' => $m->id,
            'conversation_id' => $m->conversation_id,
            'direction' => $m->direction,
            'channel' => $m->channel,
            'type' => $m->type,
            'body' => Demo::text($m->body),
            'attachment_url' => $m->payload['preview_url'] ?? null,
            'payload' => $m->payload,
            'status' => $m->status,
            'sent_by' => $m->sent_by,
            'sent_at' => $m->sent_at?->toIso8601String(),
            'created_at' => $m->created_at->toIso8601String(),
        ];
    }
}
