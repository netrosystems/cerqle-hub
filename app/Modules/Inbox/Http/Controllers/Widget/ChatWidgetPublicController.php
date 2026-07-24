<?php

namespace App\Modules\Inbox\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Modules\Inbox\Models\ChatWidget;
use App\Modules\Inbox\Services\WebchatDriver;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Services\StorageManager;
use App\Support\WebchatVisitorToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public, anonymous API consumed by the embedded website chat widget. No session
 * / CSRF. Access is scoped by: (1) the unguessable widget_key, (2) a per-widget
 * domain whitelist, and (3) a signed visitor session token bound to one
 * conversation. Realtime is by polling (GET /messages).
 */
class ChatWidgetPublicController extends Controller
{
    public function __construct(
        private readonly WebchatDriver $driver,
        private readonly StorageManager $storageManager,
    ) {}

    /** POST /widget/v1/session — start or restore a visitor's chat session. */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'visitor_id' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'string', 'max:190'],
            'avatar' => ['nullable', 'string', 'max:512'],
            'external_id' => ['nullable', 'string', 'max:190'],
            'user_hash' => ['nullable', 'string', 'max:128'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);

        $visitorId = ($data['visitor_id'] ?? '') ?: (string) Str::uuid();
        $identity = $this->resolveIdentity($widget, $data);

        $conversation = $this->driver->resolveConversation($widget, $visitorId, $identity);
        $token = WebchatVisitorToken::issue($conversation->id, $widget->widget_key, $visitorId);

        return response()->json([
            'visitor_id' => $visitorId,
            'token' => $token,
            'config' => $widget->publicConfig(),
            'online' => $this->isOnline($widget),
            'messages' => $this->mapMessages($conversation->id, $widget, 0),
        ]);
    }

    /** POST /widget/v1/messages — visitor sends a message. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'message' => ['nullable', 'string', 'max:4000'],
            'type' => ['nullable', 'in:text,audio,image'],
            'attachment' => [
                'nullable', 'file', 'max:10240',
                'mimes:jpg,jpeg,png,webp,mp3,aac,m4a,amr,ogg,oga,wav,webm',
            ],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        // Append to the exact conversation the session token is bound to (never
        // re-resolve by device id — see WebchatDriver::recordInboundMessage).
        $conversation = Conversation::where('id', $payload['c'])
            ->where('workspace_id', $widget->workspace_id)
            ->first();
        abort_if($conversation === null, 404, 'Conversation not found.');

        $type = $data['type'] ?? 'text';
        $body = trim((string) ($data['message'] ?? ''));
        $messagePayload = [];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $mimeType = $file->getMimeType() ?? 'application/octet-stream';
            $isAudioRecording = str_starts_with($mimeType, 'audio/')
                || in_array($mimeType, ['video/webm', 'application/ogg'], true);
            $isImage = str_starts_with($mimeType, 'image/');
            abort_unless($isAudioRecording || $isImage, 422, 'Only image uploads and audio recordings are accepted here.');

            $type = $isImage ? 'image' : 'audio';
            $storedPath = $this->storageManager->prefixedPath('message-media/'.$file->hashName());
            $this->storageManager->disk()->putFileAs(dirname($storedPath), $file, basename($storedPath));

            $messagePayload = [
                'preview_url' => $this->storageManager->disk()->url($storedPath),
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $mimeType,
                'caption' => $body !== '' ? $body : null,
            ];
            $body = $body !== '' ? $body : ($file->getClientOriginalName() ?: ($isImage ? 'Image attachment' : 'Voice message'));
        }

        abort_if($type === 'text' && $body === '', 422, 'Message body is required.');

        $message = $this->driver->recordInboundMessage($conversation, $payload['v'], $body, $type, $messagePayload);

        return response()->json(['message' => $this->mapMessage($message, $widget)]);
    }

    /** GET /widget/v1/messages?after=ID — poll for new messages. */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'after' => ['nullable', 'integer'],
        ]);

        $widget = $this->resolveWidget($data['key']);
        $this->assertDomainAllowed($widget, $request);
        $payload = $this->authVisitor($request, $widget);

        return response()->json([
            'messages' => $this->mapMessages((int) $payload['c'], $widget, (int) ($data['after'] ?? 0)),
            'online' => $this->isOnline($widget),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function resolveWidget(string $key): ChatWidget
    {
        return ChatWidget::where('widget_key', $key)->where('enabled', true)->firstOrFail();
    }

    /**
     * Build the trusted identity from client-supplied fields. When the widget has
     * identity verification enabled, we ONLY trust an identity accompanied by a
     * valid HMAC (client signs the external_id — or email — with the widget's
     * identity_secret on their server). Otherwise the identity is accepted as-is
     * (unverified) so simple sites still get names/emails.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveIdentity(ChatWidget $widget, array $data): array
    {
        $identity = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'avatar' => $data['avatar'] ?? null,
            'external_id' => $data['external_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($identity)) {
            return [];
        }

        if ($widget->identity_verification) {
            $signedValue = (string) ($data['external_id'] ?? ($data['email'] ?? ''));
            $provided = (string) ($data['user_hash'] ?? '');
            $expected = hash_hmac('sha256', $signedValue, (string) $widget->identity_secret);

            if ($signedValue === '' || $provided === '' || ! hash_equals($expected, $provided)) {
                return []; // unverified → treat visitor as anonymous
            }
        }

        return $identity;
    }

    /** Reject requests whose Origin/Referer host isn't in the widget whitelist. */
    private function assertDomainAllowed(ChatWidget $widget, Request $request): void
    {
        $allowed = $widget->allowed_domains ?? [];
        if (empty($allowed)) {
            return; // no whitelist configured → allow any site
        }

        $origin = $request->headers->get('Origin') ?: $request->headers->get('Referer') ?: '';
        $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        foreach ($allowed as $d) {
            $d = strtolower(preg_replace(['/^https?:\/\//', '/^www\./', '/\/.*$/'], '', (string) $d));
            if ($d !== '' && ($host === $d || str_ends_with($host, '.'.$d))) {
                return;
            }
        }

        abort(403, 'This widget is not allowed on this domain.');
    }

    /**
     * Verify the visitor session token (header X-Widget-Token, or body token).
     *
     * @return array{c:int,w:string,v:string,e:int}
     */
    private function authVisitor(Request $request, ChatWidget $widget): array
    {
        $token = $request->headers->get('X-Widget-Token') ?: (string) $request->input('token');
        $payload = $token ? WebchatVisitorToken::verify($token, $widget->widget_key) : null;

        abort_if($payload === null, 401, 'Invalid or expired session.');

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapMessages(int $conversationId, ChatWidget $widget, int $afterId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->where('id', '>', $afterId)
            ->whereIn('direction', ['in', 'out'])
            ->where('status', '!=', 'failed')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Message $m) => $this->mapMessage($m, $widget))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMessage(Message $m, ChatWidget $widget): array
    {
        $isAgent = $m->direction === 'out';

        return [
            'id' => $m->id,
            'role' => $isAgent ? 'agent' : 'visitor',
            'body' => (string) $m->body,
            'type' => $m->type,
            // Website-chat media is stored by the inbox reply action. Expose
            // only its generated public URL, never a provider media ID.
            'attachment_url' => $m->payload['preview_url'] ?? null,
            'filename' => $m->payload['filename'] ?? null,
            'sent_by' => $m->sent_by,
            'agent_name' => $isAgent ? ($widget->agent_name ?: 'Support') : null,
            'created_at' => optional($m->sent_at ?? $m->created_at)->toIso8601String(),
        ];
    }

    /** Whether the widget is inside its configured working hours (default: always). */
    private function isOnline(ChatWidget $widget): bool
    {
        $wh = $widget->working_hours_json;
        if (empty($wh) || empty($wh['enabled'])) {
            return true;
        }

        try {
            $now = now()->setTimezone($wh['timezone'] ?? 'UTC');
        } catch (\Throwable) {
            $now = now();
        }

        $dayKey = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][(int) $now->format('w')];
        $sched = $wh['schedule'][$dayKey] ?? null;
        if (empty($sched) || empty($sched['enabled'])) {
            return false;
        }

        $cur = (int) $now->format('H') * 60 + (int) $now->format('i');
        [$oh, $om] = array_pad(explode(':', (string) ($sched['open'] ?? '00:00')), 2, '0');
        [$ch, $cm] = array_pad(explode(':', (string) ($sched['close'] ?? '23:59')), 2, '0');

        return $cur >= ((int) $oh * 60 + (int) $om) && $cur < ((int) $ch * 60 + (int) $cm);
    }
}
