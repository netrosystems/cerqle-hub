<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Modules\Inbox\Models\CannedReply;
use App\Modules\Inbox\Models\InboxLabel;
use App\Modules\Inbox\Services\WebchatPresence;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\Demo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileInboxController extends WorkspaceScopedController
{
    /**
     * GET /api/v1/mobile/inbox/setup
     * Single bootstrapping call: labels, canned replies, channel accounts, team members, live visitors count.
     */
    public function setup(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $liveSince = app(WebchatPresence::class)->onlineSince();

        $labels = InboxLabel::where('workspace_id', $wsId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        $cannedReplies = CannedReply::where('workspace_id', $wsId)
            ->orderBy('shortcut')
            ->get(['id', 'shortcut', 'body']);

        $channelAccounts = ChannelAccount::where('workspace_id', $wsId)
            ->where('status', 'active')
            ->orderBy('channel')
            ->orderBy('display_name')
            ->get(['id', 'channel', 'display_name', 'phone_number_id']);

        $teamMembers = User::inWorkspace($wsId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $liveUsersCount = Conversation::where('workspace_id', $wsId)
            ->whereHas('channelAccount', fn ($account) => $account->where('channel', 'webchat'))
            ->where('webchat_last_seen_at', '>=', $liveSince)
            ->distinct('contact_id')
            ->count('contact_id');

        return response()->json([
            'labels' => $labels,
            'canned_replies' => $cannedReplies,
            'channel_accounts' => $channelAccounts->map(fn ($ca) => [
                'id' => $ca->id,
                'channel' => $ca->channel,
                'display_name' => $ca->display_name,
                'phone_number_id' => $ca->phone_number_id,
            ]),
            'team_members' => $teamMembers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ]),
            'live_users_count' => $liveUsersCount,
        ]);
    }

    /**
     * GET /api/v1/mobile/inbox/counts
     * Lightweight badge count refresh for mobile navigation.
     */
    public function counts(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $userId = $request->user()->id;
        $liveSince = app(WebchatPresence::class)->onlineSince();

        $openQuery = Conversation::where('workspace_id', $wsId)->where('status', 'open');

        return response()->json([
            'all' => (clone $openQuery)->count(),
            'mine' => (clone $openQuery)->where('assigned_user_id', $userId)->count(),
            'unassigned' => (clone $openQuery)->whereNull('assigned_user_id')->count(),
            'live' => Conversation::where('workspace_id', $wsId)
                ->whereHas('channelAccount', fn ($account) => $account->where('channel', 'webchat'))
                ->where('webchat_last_seen_at', '>=', $liveSince)
                ->distinct('contact_id')
                ->count('contact_id'),
            'unread' => Conversation::where('workspace_id', $wsId)
                ->where('unread_count', '>', 0)
                ->count(),
        ]);
    }

    /**
     * GET /api/v1/mobile/inbox/templates?conversation_uuid=xxx
     * WhatsApp approved templates for the active workspace.
     */
    public function templates(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $templates = WhatsappTemplate::where('workspace_id', $wsId)
            ->where('status', 'APPROVED')
            ->orderBy('name')
            ->get(['id', 'name', 'language', 'category', 'components']);

        return response()->json([
            'data' => $templates->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'language' => $t->language,
                'category' => $t->category,
                'components' => $t->components,
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/inbox/labels
     */
    public function labels(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $labels = InboxLabel::where('workspace_id', $wsId)->orderBy('name')->get(['id', 'name', 'color']);

        return response()->json(['data' => $labels]);
    }

    /**
     * GET /api/v1/mobile/inbox/canned-replies?search=xxx
     */
    public function cannedReplies(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $query = CannedReply::where('workspace_id', $wsId);

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('shortcut', 'like', '%'.$request->search.'%')
                    ->orWhere('body', 'like', '%'.$request->search.'%');
            });
        }

        $replies = $query->orderBy('shortcut')->get(['id', 'shortcut', 'body']);

        return response()->json(['data' => $replies]);
    }

    /**
     * GET /api/v1/mobile/contacts/search?q=xxx
     */
    public function contactSearch(Request $request): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $q = $request->input('q', '');

        $contacts = Contact::where('workspace_id', $wsId)
            ->customerDirectory()
            ->withCount([
                'conversations as has_messenger_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'messenger')),
                'conversations as has_instagram_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'instagram')),
                'conversations as has_webchat_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'webchat')),
                'conversations as has_email_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'email')),
                'conversations as has_whatsapp_thread' => fn ($q) => $q->whereHas('channelAccount', fn ($ca) => $ca->where('channel', 'whatsapp')),
            ])
            ->where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone_e164', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->orderBy('first_name')
            ->limit(30)
            ->get(['id', 'uuid', 'first_name', 'last_name', 'phone_e164', 'email', 'avatar', 'custom_fields', 'source']);

        return response()->json([
            'data' => $contacts->map(fn ($c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'name' => Demo::name($c->name),
                'phone' => Demo::phone($c->phone_e164),
                'phone_e164' => $c->phone_e164,
                'email' => Demo::email($c->email),
                'avatar' => Demo::active() ? null : $c->avatar_url,
                'custom_fields' => $c->custom_fields,
                'source' => $c->source,
                'has_messenger_thread' => (int) ($c->has_messenger_thread ?? 0) > 0,
                'has_instagram_thread' => (int) ($c->has_instagram_thread ?? 0) > 0,
                'has_webchat_thread' => (int) ($c->has_webchat_thread ?? 0) > 0,
                'has_email_thread' => (int) ($c->has_email_thread ?? 0) > 0,
                'has_whatsapp_thread' => (int) ($c->has_whatsapp_thread ?? 0) > 0,
            ]),
        ]);
    }

    /**
     * GET /api/v1/mobile/contacts/{id}
     */
    public function contact(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);

        $contact = Contact::where('workspace_id', $wsId)->customerDirectory()->findOrFail($id);

        $conversations = $contact->conversations()
            ->with('channelAccount')
            ->orderByDesc('last_message_at')
            ->limit(10)
            ->get(['id', 'uuid', 'status', 'channel_account_id', 'last_message_at', 'unread_count']);

        return response()->json([
            'id' => $contact->id,
            'name' => Demo::name($contact->name),
            'phone' => Demo::phone($contact->phone_e164),
            'email' => Demo::email($contact->email),
            'avatar' => Demo::active() ? null : $contact->avatar_url,
            'custom_fields' => Demo::active()
                ? Demo::maskArrayValues($contact->custom_fields ?? [])
                : ($contact->custom_fields ?? []),
            'created_at' => $contact->created_at->toIso8601String(),
            'conversations' => $conversations->map(fn ($c) => [
                'id' => $c->id,
                'uuid' => $c->uuid,
                'status' => $c->status,
                'channel' => $c->channelAccount?->channel,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'unread_count' => $c->unread_count,
            ]),
        ]);
    }

    /**
     * PATCH/PUT/POST /api/v1/mobile/contacts/{id}
     * Update contact name, email, phone, custom fields from mobile app.
     */
    public function updateContact(Request $request, int $id): JsonResponse
    {
        $wsId = $this->workspaceId($request);
        $contact = Contact::where('workspace_id', $wsId)->findOrFail($id);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:200'],
            'full_name' => ['nullable', 'string', 'max:200'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'phone_e164' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:4'],
            'language' => ['nullable', 'string', 'max:8'],
            'opt_in_whatsapp' => ['nullable', 'boolean'],
            'opt_in_sms' => ['nullable', 'boolean'],
            'opt_in_email' => ['nullable', 'boolean'],
            'custom_fields' => ['nullable', 'array', 'max:50'],
        ]);

        $updates = [];

        if (array_key_exists('email', $validated)) {
            $updates['email'] = $validated['email'] ? trim($validated['email']) : null;
        }

        if (array_key_exists('phone_e164', $validated)) {
            $updates['phone_e164'] = $validated['phone_e164'] ? trim($validated['phone_e164']) : null;
        } elseif (array_key_exists('phone', $validated)) {
            $updates['phone_e164'] = $validated['phone'] ? trim($validated['phone']) : null;
        }

        if (array_key_exists('country', $validated)) {
            $updates['country'] = $validated['country'] ? strtoupper(trim($validated['country'])) : null;
        }

        if (array_key_exists('language', $validated)) {
            $updates['language'] = $validated['language'] ? strtolower(trim($validated['language'])) : null;
        }

        if (array_key_exists('opt_in_whatsapp', $validated)) {
            $updates['opt_in_whatsapp'] = (bool) $validated['opt_in_whatsapp'];
        }

        if (array_key_exists('opt_in_sms', $validated)) {
            $updates['opt_in_sms'] = (bool) $validated['opt_in_sms'];
        }

        if (array_key_exists('opt_in_email', $validated)) {
            $updates['opt_in_email'] = (bool) $validated['opt_in_email'];
        }

        $submittedName = $validated['name'] ?? ($validated['full_name'] ?? null);
        if ($submittedName !== null) {
            $submittedName = trim($submittedName);
            if ($submittedName !== '') {
                $parts = explode(' ', $submittedName, 2);
                $updates['first_name'] = $parts[0];
                $updates['last_name'] = $parts[1] ?? '';
            } else {
                $updates['first_name'] = null;
                $updates['last_name'] = null;
            }
        } else {
            if (array_key_exists('first_name', $validated)) {
                $updates['first_name'] = $validated['first_name'] ? trim($validated['first_name']) : null;
            }
            if (array_key_exists('last_name', $validated)) {
                $updates['last_name'] = $validated['last_name'] ? trim($validated['last_name']) : null;
            }
        }

        if (isset($validated['custom_fields'])) {
            $updates['custom_fields'] = array_merge($contact->custom_fields ?? [], $validated['custom_fields']);
        }

        if (! empty($updates)) {
            $contact->update($updates);
            $contact->refresh();
        }

        return response()->json([
            'id' => $contact->id,
            'uuid' => $contact->uuid,
            'name' => Demo::name($contact->name),
            'first_name' => Demo::name($contact->first_name),
            'last_name' => Demo::name($contact->last_name),
            'phone' => Demo::phone($contact->phone_e164),
            'phone_e164' => $contact->phone_e164,
            'email' => Demo::email($contact->email),
            'country' => $contact->country,
            'language' => $contact->language,
            'opt_in_whatsapp' => (bool) $contact->opt_in_whatsapp,
            'opt_in_sms' => (bool) $contact->opt_in_sms,
            'opt_in_email' => (bool) $contact->opt_in_email,
            'avatar' => Demo::active() ? null : $contact->avatar_url,
            'custom_fields' => Demo::active()
                ? Demo::maskArrayValues($contact->custom_fields ?? [])
                : ($contact->custom_fields ?? []),
            'created_at' => $contact->created_at->toIso8601String(),
        ]);
    }
}
