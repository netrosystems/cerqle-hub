<?php

namespace App\Modules\Inbox\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Integrations\Services\MetaPageDiscoveryService;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InboxSetupController extends Controller
{
    public function __construct(private readonly MetaPageDiscoveryService $metaPages) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        // WhatsApp WABAs
        $wabas = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
            ->with('phoneNumbers')
            ->get();

        $whatsappChannelAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')
            ->whereNotNull('phone_number_id')
            ->get(['id', 'phone_number_id', 'display_name', 'status', 'meta_json', 'business_account_id']);

        $webhookTokensByWaba = [];
        $channelAccountPhoneIdsByWaba = [];
        $channelAccountsByWaba = [];
        foreach ($wabas as $waba) {
            $webhookTokensByWaba[$waba->id] = $waba->makeVisible('webhook_verify_token')->webhook_verify_token;
            $accounts = $whatsappChannelAccounts->where('business_account_id', $waba->waba_id)->values();
            $channelAccountPhoneIdsByWaba[$waba->id] = $accounts->pluck('phone_number_id')->all();
            $channelAccountsByWaba[$waba->id] = $accounts->map(fn ($a) => [
                'id'              => $a->id,
                'phone_number_id' => $a->phone_number_id,
                'display_name'    => $a->display_name,
                'status'          => $a->status,
                'ai_chatbot_id'   => $a->meta_json['ai_chatbot_id'] ?? null,
            ])->all();
        }

        // Instagram / Messenger
        $instagramAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'instagram')
            ->get(['id', 'display_name', 'status', 'meta_json', 'created_at'])
            ->map(fn ($a) => array_merge($a->toArray(), ['ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null]));

        $messengerAccounts = ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'messenger')
            ->get(['id', 'display_name', 'status', 'meta_json', 'created_at'])
            ->map(fn ($a) => array_merge($a->toArray(), ['ai_chatbot_id' => $a->meta_json['ai_chatbot_id'] ?? null]));

        $chatbots = AiChatbot::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->get(['id', 'name']);

        $meta = CredentialResolver::system()->meta();
        $metaWebhookUrl = $meta ? url('/webhooks/meta/'.$meta->verifyToken()) : null;

        $metaCreds = CredentialResolver::system()->meta();

        return Inertia::render('Inbox/Setup', [
            'wabas'                        => $wabas,
            'whatsappWebhookUrl'           => url('/webhooks/whatsapp'),
            'whatsappWebhookGlobalUrl'     => route('webhooks.whatsapp.global.receive'),
            'webhookTokensByWaba'          => $webhookTokensByWaba,
            'channelAccountPhoneIdsByWaba' => $channelAccountPhoneIdsByWaba,
            'channelAccountsByWaba'        => $channelAccountsByWaba,
            'instagramAccounts'            => $instagramAccounts,
            'messengerAccounts'            => $messengerAccounts,
            'chatbots'                     => $chatbots,
            'metaWebhookUrl'               => $metaWebhookUrl,
            'metaAppId'                    => $metaCreds?->appId() ?: null,
            'metaConfigIdWhatsapp'         => $metaCreds?->configIdWhatsapp() ?: null,
            'metaConfigIdSocial'           => $metaCreds?->configIdSocial() ?: null,
        ]);
    }

    public function embeddedSignupInstagram(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'required_without:selection_token', 'string', 'max:2048'],
            'selection_token' => ['nullable', 'required_without:code', 'string', 'max:96'],
            'selected_instagram_account_id' => ['nullable', 'string', 'max:64'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        if (! CredentialResolver::system()->meta()?->appId()) {
            return response()->json(['message' => 'Meta App credentials are not configured. Please ask your administrator to configure them in Admin → Integrations → Meta App.'], 422);
        }

        if (! empty($validated['selection_token'])) {
            $pending = $request->session()->pull('instagram_connect_selection.'.$validated['selection_token']);
            if (! is_array($pending) || (int) ($pending['workspace_id'] ?? 0) !== (int) $workspaceId) {
                return response()->json(['message' => 'Instagram selection expired. Please reconnect with Meta and choose the account again.'], 422);
            }

            $selectedIgId = (string) ($validated['selected_instagram_account_id'] ?? '');
            $selectedPage = collect($pending['pages'] ?? [])->first(function (array $page) use ($selectedIgId): bool {
                return (string) ($page['instagram_business_account']['id'] ?? '') === $selectedIgId;
            });

            if (! $selectedPage) {
                return response()->json(['message' => 'Choose one Instagram account to connect.'], 422);
            }

            $pages = [$selectedPage];
        } else {
            // Ensure the Meta App delivers `instagram` webhook events to our endpoint.
            // Without this app-level subscription Meta has no callback URL for the
            // instagram object, so inbound Instagram messages never reach the server.
            if (! $this->registerInstagramAppWebhook()) {
                return response()->json([
                    'message' => 'Meta authorization succeeded, but Cerqle could not register the Instagram webhook. Check the app secret, verify token, public HTTPS callback URL, and Meta app permissions.',
                ], 422);
            }

            $accessToken = $this->exchangeCodeForToken($validated['code']);
            if (! $accessToken) {
                return response()->json(['message' => 'Failed to exchange authorization code with Meta.'], 422);
            }

            $longToken = $this->exchangeForLongLivedToken($accessToken);
            if (! $longToken) {
                return response()->json(['message' => 'Meta authorization succeeded, but the long-lived token exchange failed. Reconnect and try again.'], 422);
            }

            // Include classic Page roles plus Business Portfolio owned/client Pages.
            // A successful but empty /me/accounts response is common for New Pages
            // Experience assets assigned only through a Business Portfolio.
            $discovery = $this->metaPages->discover(
                $longToken,
                'id,name,access_token,instagram_business_account{id,name,username}',
            );
            $pages = $discovery['pages'];
            $selectedPageIds = $this->selectedMetaTargetIds($longToken, ['pages_show_list']);
            $selectedInstagramIds = $this->selectedMetaTargetIds($longToken, ['instagram_basic']);

            // Facebook Login for Business can expose every Page discoverable via a
            // Business Portfolio. Respect the assets selected in the Meta popup so
            // connecting Instagram does not unexpectedly connect unrelated Pages.
            if ($selectedPageIds !== [] || $selectedInstagramIds !== []) {
                $pages = $this->filterPagesToSelectedTargets($pages, $selectedPageIds, $selectedInstagramIds);
            }

            if ($discovery['errors'] !== []) {
                Log::warning('Instagram embedded signup: some Page discovery sources failed', [
                    'workspace_id' => $workspaceId,
                    'errors' => $discovery['errors'],
                    'successful_sources' => $discovery['successful_sources'],
                ]);
            }

            if ($pages === [] && $discovery['successful_sources'] === []) {
                return response()->json([
                    'message' => 'Could not fetch your Facebook pages: '.($discovery['errors'][0]['message'] ?? 'unknown error'),
                ], 422);
            }

            $connectablePages = $this->instagramConnectablePages($pages);
            if (count($connectablePages) > 1) {
                $selectionToken = Str::random(48);
                $request->session()->put('instagram_connect_selection.'.$selectionToken, [
                    'workspace_id' => $workspaceId,
                    'pages' => $connectablePages,
                ]);

                return response()->json([
                    'requires_selection' => true,
                    'selection_token' => $selectionToken,
                    'message' => 'Choose the Instagram account you want to connect.',
                    'accounts' => collect($connectablePages)->map(fn (array $page) => [
                        'instagram_account_id' => (string) ($page['instagram_business_account']['id'] ?? ''),
                        'name' => (string) ($page['instagram_business_account']['username']
                            ?? $page['instagram_business_account']['name']
                            ?? $page['name']
                            ?? $page['instagram_business_account']['id']),
                        'facebook_page_id' => (string) ($page['id'] ?? ''),
                        'facebook_page_name' => (string) ($page['name'] ?? ''),
                    ])->values()->all(),
                ], 409);
            }
        }

        $connected = 0;
        $workspaceConflicts = 0;

        Log::info('Instagram embedded signup: pages fetched', [
            'workspace_id' => $workspaceId,
            'page_count'   => count($pages),
            'pages'        => collect($pages)->map(fn ($p) => [
                'id'   => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
                'has_instagram' => isset($p['instagram_business_account']['id']),
            ])->all(),
        ]);

        foreach ($pages as $page) {
            $igAccount = $page['instagram_business_account'] ?? null;
            if (! $igAccount || empty($igAccount['id'])) {
                continue;
            }

            $pageToken = $page['access_token'] ?? null;
            $pageId    = (string) ($page['id'] ?? '');
            $igId      = (string) $igAccount['id'];
            $name      = $igAccount['username'] ?? $igAccount['name'] ?? $page['name'] ?? $igId;

            if (! is_string($pageToken) || $pageToken === '') {
                Log::warning('Instagram embedded signup: Page access token missing; account skipped', [
                    'workspace_id' => $workspaceId,
                    'facebook_page_id' => $pageId,
                    'instagram_account_id' => $igId,
                ]);
                continue;
            }

            // Inbound routing is keyed by Meta's global Instagram account ID. If
            // the same client reconnects from another workspace they can access,
            // move/update the row instead of leaving a stale hidden connection.
            // Still block accounts that belong to a workspace this user cannot
            // access, because routing would be unsafe across tenants.
            $existingAnyWorkspace = ChannelAccount::where('channel', 'instagram')
                ->whereJsonContains('meta_json->instagram_page_id', $igId)
                ->first();
            if ($existingAnyWorkspace
                && (int) $existingAnyWorkspace->workspace_id !== (int) $workspaceId
                && ! in_array((int) $existingAnyWorkspace->workspace_id, $this->accessibleWorkspaceIds($request), true)) {
                $workspaceConflicts++;
                Log::warning('Instagram embedded signup: account already belongs to another workspace', [
                    'workspace_id' => $workspaceId,
                    'existing_workspace_id' => $existingAnyWorkspace->workspace_id,
                    'instagram_account_id' => $igId,
                ]);
                continue;
            }

            // Subscribe the Page to Instagram messaging webhooks. Without this Meta
            // never delivers inbound Instagram messages to our /webhooks/meta endpoint.
            if (! $this->subscribePageToInstagram($pageId, $pageToken)) {
                continue;
            }

            // Persisted shape mirrors InboxDemoSeeder + what InstagramDriver expects:
            // - credentials.instagram_account_id / access_token  → used by send()
            // - meta_json.instagram_page_id (= IG account id, matches webhook entry.id)
            // - meta_json.instagram_account_id / facebook_page_id → diagnostics + lookup
            $credentials = ['access_token' => $pageToken, 'instagram_account_id' => $igId];
            $metaJson    = [
                'instagram_page_id'    => $igId,
                'instagram_account_id' => $igId,
                'facebook_page_id'     => $pageId,
            ];

            $existing = $existingAnyWorkspace ?: ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'instagram')
                ->whereJsonContains('meta_json->instagram_page_id', $igId)
                ->first();
            $alreadyExists = $existing !== null;

            if ($existing) {
                // Update the token on re-connect (preserve any extra meta_json keys
                // such as an assigned ai_chatbot_id by merging rather than replacing).
                $existing->update([
                    'workspace_id' => $workspaceId,
                    'display_name' => mb_substr((string) $name, 0, 128),
                    'credentials' => $credentials,
                    'meta_json'   => array_merge($existing->meta_json ?? [], $metaJson),
                    'status'      => 'active',
                ]);
            } else {
                ChannelAccount::create([
                    'workspace_id' => $workspaceId,
                    'channel'      => 'instagram',
                    'provider'     => 'meta',
                    'display_name' => mb_substr((string) $name, 0, 128),
                    'credentials'  => $credentials,
                    'meta_json'    => $metaJson,
                    'status'       => 'active',
                ]);
            }

            Log::info('Instagram embedded signup: account connected', [
                'workspace_id'         => $workspaceId,
                'facebook_page_id'     => $pageId,
                'instagram_account_id' => $igId,
                'reconnect'            => $alreadyExists,
            ]);

            $connected++;
        }

        if ($connected === 0) {
            if ($workspaceConflicts > 0) {
                return response()->json([
                    'message' => 'This Instagram account is already connected to another workspace. Disconnect it there first; one Meta account cannot be routed safely to multiple workspaces.',
                ], 409);
            }

            $pageCount = count($pages);
            $message = $pageCount === 0
                ? 'No Facebook Pages were returned. Reconnect and grant pages_show_list and business_management; the latter is required for Pages assigned through a Meta Business Portfolio.'
                : 'No Instagram Business accounts were found on your ' . $pageCount . ' authorized page(s). To fix this: (1) Go to Meta Business Suite → your Facebook Page → Linked Accounts → link your Instagram account. (2) Make sure your Instagram is a Professional (Business or Creator) account. (3) Ensure your Social Embedded Signup config includes the instagram_basic permission.';

            return response()->json(['message' => $message], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected]);
    }

    public function embeddedSignupMessenger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'required_without:selection_token', 'string', 'max:2048'],
            'selection_token' => ['nullable', 'required_without:code', 'string', 'max:96'],
            'selected_facebook_page_id' => ['nullable', 'string', 'max:64'],
        ]);

        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        if (! CredentialResolver::system()->meta()?->appId()) {
            return response()->json(['message' => 'Meta App credentials are not configured. Please ask your administrator to configure them in Admin → Integrations → Meta App.'], 422);
        }

        if (! empty($validated['selection_token'])) {
            $pending = $request->session()->pull('messenger_connect_selection.'.$validated['selection_token']);
            if (! is_array($pending) || (int) ($pending['workspace_id'] ?? 0) !== (int) $workspaceId) {
                return response()->json(['message' => 'Facebook Page selection expired. Please reconnect with Meta and choose the Page again.'], 422);
            }

            $selectedPageId = (string) ($validated['selected_facebook_page_id'] ?? '');
            $selectedPage = collect($pending['pages'] ?? [])->first(function (array $page) use ($selectedPageId): bool {
                return (string) ($page['id'] ?? '') === $selectedPageId;
            });

            if (! $selectedPage) {
                return response()->json(['message' => 'Choose one Facebook Page to connect.'], 422);
            }

            $pages = [$selectedPage];
        } else {
            // Ensure the Meta App delivers `page` (Messenger) webhook events to our
            // endpoint. Without this app-level subscription Meta has no callback URL for
            // the page object, so inbound Messenger messages never reach the server.
            if (! $this->registerMessengerAppWebhook()) {
                return response()->json([
                    'message' => 'Meta authorization succeeded, but Cerqle could not register the Messenger webhook. Check the app secret, verify token, public HTTPS callback URL, and Meta app permissions.',
                ], 422);
            }

            $accessToken = $this->exchangeCodeForToken($validated['code']);
            if (! $accessToken) {
                return response()->json(['message' => 'Failed to exchange authorization code with Meta.'], 422);
            }

            $longToken = $this->exchangeForLongLivedToken($accessToken);
            if (! $longToken) {
                return response()->json(['message' => 'Meta authorization succeeded, but the long-lived token exchange failed. Reconnect and try again.'], 422);
            }

            $discovery = $this->metaPages->discover($longToken, 'id,name,access_token');
            $pages = $discovery['pages'];
            $selectedPageIds = $this->selectedMetaTargetIds($longToken, ['pages_show_list']);

            // Avoid auto-connecting every Page the user/business can discover. Meta's
            // debug_token response tells us which Page assets were selected in the
            // OAuth dialog; use that as the source of truth when present.
            if ($selectedPageIds !== []) {
                $pages = $this->filterPagesToSelectedTargets($pages, $selectedPageIds);
            }

            if ($discovery['errors'] !== []) {
                Log::warning('Messenger embedded signup: some Page discovery sources failed', [
                    'workspace_id' => $workspaceId,
                    'errors' => $discovery['errors'],
                    'successful_sources' => $discovery['successful_sources'],
                ]);
            }

            if ($pages === [] && $discovery['successful_sources'] === []) {
                return response()->json([
                    'message' => 'Could not fetch your Facebook pages: '.($discovery['errors'][0]['message'] ?? 'unknown error'),
                ], 422);
            }

            $connectablePages = $this->messengerConnectablePages($pages);
            if (count($connectablePages) > 1) {
                $selectionToken = Str::random(48);
                $request->session()->put('messenger_connect_selection.'.$selectionToken, [
                    'workspace_id' => $workspaceId,
                    'pages' => $connectablePages,
                ]);

                return response()->json([
                    'requires_selection' => true,
                    'selection_token' => $selectionToken,
                    'message' => 'Choose the Facebook Page you want to connect.',
                    'accounts' => collect($connectablePages)->map(fn (array $page) => [
                        'facebook_page_id' => (string) ($page['id'] ?? ''),
                        'name' => (string) ($page['name'] ?? $page['id']),
                    ])->values()->all(),
                ], 409);
            }
        }

        $connected = 0;
        $workspaceConflicts = 0;

        Log::info('Messenger embedded signup: pages fetched', [
            'workspace_id' => $workspaceId,
            'page_count'   => count($pages),
            'pages'        => collect($pages)->map(fn ($p) => [
                'id'   => $p['id'] ?? null,
                'name' => $p['name'] ?? null,
            ])->all(),
        ]);

        foreach ($pages as $page) {
            $pageId    = (string) ($page['id'] ?? '');
            $pageName  = $page['name'] ?? $pageId;
            $pageToken = $page['access_token'] ?? null;

            if (! $pageId) {
                continue;
            }

            // A PAGE access token is mandatory: it is what authorises send() and the
            // User Profile API (name/picture). If /me/accounts didn't include one,
            // fetch it explicitly. Never fall back to the user token — a user token
            // cannot resolve page-scoped PSIDs and yields Graph error 100.
            if (! $pageToken) {
                $tokenRes  = Http::withToken($longToken)
                    ->get("https://graph.facebook.com/v25.0/{$pageId}", ['fields' => 'access_token']);
                $pageToken = $tokenRes->json('access_token');
            }

            if (! $pageToken) {
                Log::warning('Messenger embedded signup: no page access token — page skipped', [
                    'workspace_id' => $workspaceId,
                    'page_id'      => $pageId,
                    'page_name'    => $pageName,
                ]);

                continue;
            }

            $existingAnyWorkspace = ChannelAccount::where('channel', 'messenger')
                ->whereJsonContains('meta_json->page_id', $pageId)
                ->first();
            if ($existingAnyWorkspace
                && (int) $existingAnyWorkspace->workspace_id !== (int) $workspaceId
                && ! in_array((int) $existingAnyWorkspace->workspace_id, $this->accessibleWorkspaceIds($request), true)) {
                $workspaceConflicts++;
                Log::warning('Messenger embedded signup: Page already belongs to another workspace', [
                    'workspace_id' => $workspaceId,
                    'existing_workspace_id' => $existingAnyWorkspace->workspace_id,
                    'page_id' => $pageId,
                ]);
                continue;
            }

            // Subscribe the page to Messenger webhooks. Persist it only after
            // Meta confirms the subscription; otherwise the UI would show an
            // active account that can never receive a message.
            if (! $this->subscribePageToMessenger($pageId, $pageToken)) {
                continue;
            }

            $existing = $existingAnyWorkspace ?: ChannelAccount::where('workspace_id', $workspaceId)
                ->where('channel', 'messenger')
                ->whereJsonContains('meta_json->page_id', $pageId)
                ->first();
            $alreadyExists = $existing !== null;

            if ($existing) {
                // Update through the model instance (NOT the query builder) so the
                // `encrypted:array` cast runs and the page token is stored encrypted.
                // A query-builder update() bypasses casts and corrupts credentials,
                // which then fail to decrypt — breaking send() and the profile fetch.
                // Merge meta_json so an assigned ai_chatbot_id is preserved.
                $existing->update([
                    'workspace_id' => $workspaceId,
                    'display_name' => mb_substr((string) $pageName, 0, 128),
                    'credentials' => ['page_access_token' => $pageToken],
                    'meta_json'   => array_merge($existing->meta_json ?? [], ['page_id' => $pageId]),
                    'status'      => 'active',
                ]);
            } else {
                ChannelAccount::create([
                    'workspace_id' => $workspaceId,
                    'channel'      => 'messenger',
                    'provider'     => 'meta',
                    'display_name' => mb_substr((string) $pageName, 0, 128),
                    'credentials'  => ['page_access_token' => $pageToken],
                    'meta_json'    => ['page_id' => $pageId],
                    'status'       => 'active',
                ]);
            }

            Log::info('Messenger embedded signup: account connected', [
                'workspace_id' => $workspaceId,
                'page_id'      => $pageId,
                'reconnect'    => $alreadyExists,
            ]);

            $connected++;
        }

        if ($connected === 0) {
            if ($workspaceConflicts > 0) {
                return response()->json([
                    'message' => 'This Facebook Page is already connected to another workspace. Disconnect it there first; one Page cannot be routed safely to multiple workspaces.',
                ], 409);
            }

            return response()->json([
                'message' => 'No Facebook Pages found. Reconnect and grant pages_show_list and business_management so Pages assigned through a Meta Business Portfolio can be discovered.',
            ], 422);
        }

        return response()->json(['success' => true, 'connected' => $connected]);
    }

    private function exchangeCodeForToken(string $code): ?string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return null;
        }

        $res = Http::get('https://graph.facebook.com/v25.0/oauth/access_token', [
            'client_id'     => $meta->appId(),
            'client_secret' => $meta->appSecret(),
            'code'          => $code,
            'redirect_uri'  => '',
        ]);

        if (! $res->successful() || ! $res->json('access_token')) {
            Log::warning('Meta embedded signup: code exchange failed', [
                'response' => $res->json(),
            ]);
            return null;
        }

        return $res->json('access_token');
    }

    private function exchangeForLongLivedToken(string $shortToken): ?string
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return null;
        }

        $res = Http::get('https://graph.facebook.com/v25.0/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $meta->appId(),
            'client_secret'     => $meta->appSecret(),
            'fb_exchange_token' => $shortToken,
        ]);

        return ($res->successful() && $res->json('access_token')) ? $res->json('access_token') : null;
    }

    /**
     * Return asset IDs Meta says were selected for one or more granular scopes.
     * If Meta does not include target_ids, callers keep their existing fallback
     * discovery behaviour so older/quirky responses do not hard-fail connects.
     *
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function selectedMetaTargetIds(string $accessToken, array $scopes): array
    {
        $meta = CredentialResolver::system()->meta();
        if (! $meta?->appId() || ! $meta?->appSecret()) {
            return [];
        }

        try {
            $response = Http::get('https://graph.facebook.com/v25.0/debug_token', [
                'input_token' => $accessToken,
                'access_token' => $meta->appId().'|'.$meta->appSecret(),
            ]);

            if (! $response->successful()) {
                Log::warning('Meta embedded signup: token inspection failed', [
                    'response' => $response->json(),
                ]);

                return [];
            }

            $targetIds = [];
            foreach ((array) $response->json('data.granular_scopes', []) as $scope) {
                if (! in_array((string) ($scope['scope'] ?? ''), $scopes, true)) {
                    continue;
                }

                foreach ((array) ($scope['target_ids'] ?? []) as $targetId) {
                    if (is_string($targetId) || is_int($targetId)) {
                        $targetIds[] = (string) $targetId;
                    }
                }
            }

            return array_values(array_unique($targetIds));
        } catch (\Throwable $e) {
            Log::warning('Meta embedded signup: token inspection exception', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $selectedPageIds
     * @param  list<string>  $selectedInstagramIds
     * @return list<array<string, mixed>>
     */
    private function filterPagesToSelectedTargets(array $pages, array $selectedPageIds, array $selectedInstagramIds = []): array
    {
        return array_values(array_filter($pages, function (array $page) use ($selectedPageIds, $selectedInstagramIds): bool {
            $pageId = (string) ($page['id'] ?? '');
            $igId = (string) ($page['instagram_business_account']['id'] ?? '');

            return ($pageId !== '' && in_array($pageId, $selectedPageIds, true))
                || ($igId !== '' && in_array($igId, $selectedInstagramIds, true));
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function instagramConnectablePages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $page): bool {
            return ! empty($page['access_token'])
                && ! empty($page['id'])
                && ! empty($page['instagram_business_account']['id']);
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function messengerConnectablePages(array $pages): array
    {
        return array_values(array_filter($pages, function (array $page): bool {
            return ! empty($page['id']);
        }));
    }

    /** @return list<int> */
    private function accessibleWorkspaceIds(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $ids = collect([
            $user->workspace_id ?? null,
            $user->current_workspace_id ?? null,
        ]);

        if (method_exists($user, 'accessibleWorkspaces')) {
            $ids = $ids->merge($user->accessibleWorkspaces()->pluck('id'));
        }

        return $ids
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Register the app-level `page` (Messenger) webhook subscription so Meta knows
     * which callback URL to deliver Messenger events to. Uses the App Access Token
     * ({app_id}|{app_secret}) and points at our /webhooks/meta/{verify_token}
     * endpoint. Idempotent: re-registering the same URL/fields is a no-op on Meta.
     * Mirrors registerInstagramAppWebhook() — without it inbound Messenger messages
     * never reach the server (no app-level callback for the page object).
     */
    private function registerMessengerAppWebhook(): bool
    {
        $meta        = CredentialResolver::system()->meta();
        $appId       = $meta?->appId();
        $appSecret   = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();

        if (! $appId || ! $appSecret || ! $verifyToken) {
            Log::warning('Messenger embedded signup: cannot register app webhook — missing app id/secret/verify token', [
                'has_app_id'       => (bool) $appId,
                'has_app_secret'   => (bool) $appSecret,
                'has_verify_token' => (bool) $verifyToken,
            ]);

            return false;
        }

        $callbackUrl = route('webhooks.meta.receive', ['token' => $verifyToken]);

        try {
            $res = Http::post("https://graph.facebook.com/v25.0/{$appId}/subscriptions", [
                'access_token' => $appId . '|' . $appSecret,
                'object'       => 'page',
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields'       => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
            ]);

            if (! $res->successful()) {
                Log::warning('Messenger embedded signup: app webhook registration failed', [
                    'callback_url' => $callbackUrl,
                    'status'       => $res->status(),
                    'response'     => $res->json(),
                ]);

                return false;
            }

            Log::info('Messenger embedded signup: app webhook registered', [
                'callback_url' => $callbackUrl,
                'response'     => $res->json(),
            ]);

            // Read back what Meta actually stored so we can confirm the page object
            // has our callback URL and is marked active.
            $check = Http::get("https://graph.facebook.com/v25.0/{$appId}/subscriptions", [
                'access_token' => $appId . '|' . $appSecret,
            ]);
            Log::info('Messenger embedded signup: app subscriptions snapshot', [
                'status'   => $check->status(),
                'response' => $check->json(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Messenger embedded signup: app webhook registration exception', [
                'callback_url' => $callbackUrl,
                'error'        => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function subscribePageToMessenger(string $pageId, string $pageToken): bool
    {
        if ($pageId === '') {
            Log::warning('Messenger embedded signup: cannot subscribe — empty page id');

            return false;
        }

        try {
            $res = Http::withToken($pageToken)
                ->post("https://graph.facebook.com/v25.0/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads',
                ]);

            if (! $res->successful()) {
                Log::warning('Messenger embedded signup: page subscription failed', [
                    'page_id'  => $pageId,
                    'status'   => $res->status(),
                    'response' => $res->json(),
                ]);

                return false;
            }

            Log::info('Messenger embedded signup: page subscribed for messaging', [
                'page_id'  => $pageId,
                'response' => $res->json(),
            ]);

            // Read back the page's subscribed_apps so we can confirm which fields
            // (must include "messages") are actually active for our app.
            $check = Http::withToken($pageToken)
                ->get("https://graph.facebook.com/v25.0/{$pageId}/subscribed_apps");
            Log::info('Messenger embedded signup: page subscribed_apps snapshot', [
                'page_id'  => $pageId,
                'status'   => $check->status(),
                'response' => $check->json(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Messenger embedded signup: page subscription exception', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Register the app-level `instagram` webhook subscription so Meta knows which
     * callback URL to deliver Instagram events to. Uses the App Access Token
     * ({app_id}|{app_secret}) and points at our /webhooks/meta/{verify_token}
     * endpoint. Idempotent: re-registering the same URL/fields is a no-op on Meta.
     */
    private function registerInstagramAppWebhook(): bool
    {
        $meta        = CredentialResolver::system()->meta();
        $appId       = $meta?->appId();
        $appSecret   = $meta?->appSecret();
        $verifyToken = $meta?->verifyToken();

        if (! $appId || ! $appSecret || ! $verifyToken) {
            Log::warning('Instagram embedded signup: cannot register app webhook — missing app id/secret/verify token', [
                'has_app_id'       => (bool) $appId,
                'has_app_secret'   => (bool) $appSecret,
                'has_verify_token' => (bool) $verifyToken,
            ]);

            return false;
        }

        $callbackUrl = route('webhooks.meta.receive', ['token' => $verifyToken]);

        try {
            $res = Http::post("https://graph.facebook.com/v25.0/{$appId}/subscriptions", [
                'access_token' => $appId . '|' . $appSecret,
                'object'       => 'instagram',
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields'       => 'messages,messaging_postbacks,message_reactions',
            ]);

            if (! $res->successful()) {
                Log::warning('Instagram embedded signup: app webhook registration failed', [
                    'callback_url' => $callbackUrl,
                    'status'       => $res->status(),
                    'response'     => $res->json(),
                ]);

                return false;
            }

            Log::info('Instagram embedded signup: app webhook registered', [
                'callback_url' => $callbackUrl,
                'response'     => $res->json(),
            ]);

            // Read back what Meta actually stored so we can confirm the instagram
            // object has our callback URL and is marked active.
            $check = Http::get("https://graph.facebook.com/v25.0/{$appId}/subscriptions", [
                'access_token' => $appId . '|' . $appSecret,
            ]);
            Log::info('Instagram embedded signup: app subscriptions snapshot', [
                'status'   => $check->status(),
                'response' => $check->json(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Instagram embedded signup: app webhook registration exception', [
                'callback_url' => $callbackUrl,
                'error'        => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Subscribe the Facebook Page (linked to an Instagram professional account) to
     * Instagram messaging webhooks. This is required for Meta to deliver inbound
     * Instagram messages — the connect flow previously skipped it, so no messages
     * ever reached /webhooks/meta.
     */
    private function subscribePageToInstagram(string $pageId, string $pageToken): bool
    {
        if ($pageId === '') {
            Log::warning('Instagram embedded signup: cannot subscribe — empty page id');

            return false;
        }

        try {
            $res = Http::withToken($pageToken)
                ->post("https://graph.facebook.com/v25.0/{$pageId}/subscribed_apps", [
                    'subscribed_fields' => 'messages,messaging_postbacks,message_reactions,message_reads',
                ]);

            if (! $res->successful()) {
                Log::warning('Instagram embedded signup: page subscription failed', [
                    'page_id'  => $pageId,
                    'status'   => $res->status(),
                    'response' => $res->json(),
                ]);

                return false;
            }

            Log::info('Instagram embedded signup: page subscribed for messaging', [
                'page_id'  => $pageId,
                'response' => $res->json(),
            ]);

            // Read back the page's subscribed_apps so we can confirm which fields
            // (must include "messages") are actually active for our app.
            $check = Http::withToken($pageToken)
                ->get("https://graph.facebook.com/v25.0/{$pageId}/subscribed_apps");
            Log::info('Instagram embedded signup: page subscribed_apps snapshot', [
                'page_id'  => $pageId,
                'status'   => $check->status(),
                'response' => $check->json(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Instagram embedded signup: page subscription exception', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function assignChatbot(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);

        $validated = $request->validate([
            'chatbot_id' => ['nullable', 'integer'],
        ]);

        $chatbotId = $validated['chatbot_id'] ?? null;

        if ($chatbotId !== null) {
            $exists = AiChatbot::where('id', $chatbotId)
                ->where('workspace_id', $workspaceId)
                ->where('enabled', true)
                ->exists();
            abort_unless($exists, 422, 'Chatbot not found or not enabled.');
        }

        $meta = $channelAccount->meta_json ?? [];
        if ($chatbotId === null) {
            unset($meta['ai_chatbot_id']);
        } else {
            $meta['ai_chatbot_id'] = $chatbotId;
        }
        $channelAccount->update(['meta_json' => $meta]);

        $label = $chatbotId ? 'Chatbot assigned.' : 'Chatbot removed.';

        return back()->with('success', $label);
    }

    public function destroy(Request $request, ChannelAccount $channelAccount): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        abort_unless((int) $channelAccount->workspace_id === (int) $workspaceId, 403);
        abort_unless(in_array($channelAccount->channel, ['instagram', 'messenger'], true), 403);

        $this->unsubscribeMetaPageBestEffort($channelAccount);
        $channelAccount->delete();

        return back()->with('success', 'Account disconnected.');
    }

    private function unsubscribeMetaPageBestEffort(ChannelAccount $channelAccount): void
    {
        $meta = $channelAccount->meta_json ?? [];
        $pageId = $channelAccount->channel === 'instagram'
            ? (string) ($meta['facebook_page_id'] ?? '')
            : (string) ($meta['page_id'] ?? '');
        $pageToken = (string) (($channelAccount->credentials ?? [])['page_access_token'] ?? '');

        if ($pageId === '' || $pageToken === '') {
            return;
        }

        try {
            Http::withToken($pageToken)->delete("https://graph.facebook.com/v25.0/{$pageId}/subscribed_apps");
        } catch (\Throwable $e) {
            Log::warning('Meta Page unsubscribe failed while disconnecting channel account', [
                'channel_account_id' => $channelAccount->id,
                'channel' => $channelAccount->channel,
                'page_id' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
