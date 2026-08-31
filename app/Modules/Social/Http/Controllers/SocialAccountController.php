<?php

namespace App\Modules\Social\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integrations\Services\MetaPageDiscoveryService;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use App\Modules\Social\Services\OAuth\OAuthManager;
use App\Modules\Social\Services\SocialAccessTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

class SocialAccountController extends Controller
{
    private array $drivers;

    public function __construct(
        private readonly OAuthManager $oauth,
        private readonly MetaPageDiscoveryService $metaPages,
        private readonly SocialAccessTokenService $accessTokens,
    ) {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
        ];
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $accounts = SocialAccount::where('workspace_id', $wid)->get()->map(function (SocialAccount $account): SocialAccount {
            if (! $account->isTokenExpired()) {
                return $account;
            }

            try {
                return $this->accessTokens->fresh($account);
            } catch (\Throwable $e) {
                Log::warning('Expired social token could not be refreshed while loading accounts.', [
                    'workspace_id' => $account->workspace_id,
                    'network' => $account->network,
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);

                return $account;
            }
        });

        return Inertia::render('Social/Accounts/Index', ['accounts' => $accounts]);
    }

    public function creatorOptions(Request $request, SocialAccount $account): JsonResponse
    {
        abort_unless((int) $account->workspace_id === $this->workspaceId($request), 403);
        abort_unless($account->network === 'tiktok', 422);

        try {
            $account = $this->accessTokens->fresh($account);

            return response()->json(['data' => (new TikTokDriver)->creatorOptions($account)]);
        } catch (\Throwable $e) {
            Log::warning('TikTok creator options lookup failed.', [
                'workspace_id' => $account->workspace_id,
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'TikTok publishing options could not be loaded. Reconnect the account and try again.'], 422);
        }
    }

    public function connect(Request $request, string $network): RedirectResponse
    {
        $validNetworks = ['facebook', 'instagram', 'linkedin', 'youtube', 'tiktok'];
        abort_unless(in_array($network, $validNetworks, true), 404);

        Session::put('social_oauth_workspace', $this->workspaceId($request));

        $callbackUrl = route('client.social.oauth.callback', $network);

        try {
            $authUrl = $this->oauth->getAuthUrl($network, $this->workspaceId($request), $callbackUrl);
        } catch (\RuntimeException $e) {
            return redirect()->route('client.social.accounts.index')
                ->with('error', "OAuth for {$network} is not configured. Please contact your administrator.");
        }

        return redirect($authUrl);
    }

    public function callback(Request $request, string $network): RedirectResponse
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');
        $wid = Session::get('social_oauth_workspace', $this->workspaceId($request));
        $stored = Session::pull('social_oauth_state', []);

        if ($error || ! $code) {
            return redirect()->route('client.social.accounts.index')->with('error', 'OAuth failed: '.($error ?? 'No code received'));
        }

        // Verify state to prevent OAuth CSRF / account-linking hijack
        if (empty($stored['state']) || ! hash_equals($stored['state'], (string) $state)) {
            return redirect()->route('client.social.accounts.index')->with('error', 'Invalid OAuth state. Please try connecting again.');
        }

        $callbackUrl = route('client.social.oauth.callback', $network);
        try {
            $tokens = $this->oauth->exchangeCode($network, $code, $callbackUrl, $stored);

            if (empty($tokens['access_token'])) {
                return redirect()->route('client.social.accounts.index')->with('error', 'Failed to obtain access token.');
            }

            // Meta connections are resolved through Page/Business discovery
            // below. Calling the Instagram Basic Display `/me` endpoint here
            // with a Facebook Login token can fail even when Page discovery is
            // valid, turning a good Instagram connection into a false error.
            $driver = $this->drivers[$network] ?? null;
            $accountInfo = in_array($network, ['facebook', 'instagram'], true)
                ? ['account_id' => '', 'name' => '', 'picture_url' => null]
                : ($driver
                    ? $driver->fetchAccountInfo($tokens['access_token'])
                    : ['account_id' => '', 'name' => '', 'picture_url' => null]);
        } catch (\Throwable $e) {
            Log::warning('Social OAuth callback failed', [
                'workspace_id' => $wid,
                'network' => $network,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('client.social.accounts.index')
                ->with('error', ucfirst($network).' authorization failed: '.mb_substr($e->getMessage(), 0, 240));
        }

        // For Facebook / Instagram: fetch pages the user manages and upsert each one.
        if (in_array($network, ['facebook', 'instagram'])) {
            $fields = $network === 'instagram'
                ? 'id,name,access_token,picture,instagram_business_account{id,name,username,profile_picture_url}'
                : 'id,name,access_token,picture';

            $discovery = $this->metaPages->discover($tokens['access_token'], $fields);
            $pages = $discovery['pages'];

            $selectionScopes = $network === 'instagram'
                ? ['instagram_content_publish', 'instagram_manage_contents', 'instagram_basic', 'pages_read_engagement', 'pages_show_list']
                : ['pages_manage_posts', 'pages_read_engagement', 'pages_show_list'];

            try {
                $selectedTargetIds = $this->oauth->selectedMetaTargetIds(
                    $network,
                    $tokens['access_token'],
                    $selectionScopes,
                );
                $grantedScopes = $this->oauth->grantedMetaScopes($network, $tokens['access_token']);
            } catch (\Throwable $e) {
                Log::warning('Social OAuth: Meta token capabilities could not be verified', [
                    'workspace_id' => $wid,
                    'network' => $network,
                    'error' => $e->getMessage(),
                ]);

                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'Meta authorization succeeded, but Cerqle could not verify the selected account and permissions. No accounts were connected. Please try again.');
            }

            if ($selectedTargetIds === []) {
                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'Meta did not return a selected Page or Instagram account. Reconnect and choose the specific account you want to add.');
            }

            $pages = $this->filterMetaPagesToSelectedTargets($pages, $selectedTargetIds);

            if ($discovery['errors'] !== []) {
                Log::warning('Social OAuth: one or more Meta Page discovery sources failed', [
                    'workspace_id' => $wid,
                    'network' => $network,
                    'errors' => $discovery['errors'],
                    'successful_sources' => $discovery['successful_sources'],
                ]);
            }

            if (empty($pages)) {
                if ($discovery['successful_sources'] === []) {
                    $message = $discovery['errors'][0]['message'] ?? 'Unknown Graph API error.';

                    return redirect()->route('client.social.accounts.index')
                        ->with('error', 'Could not fetch your '.ucfirst($network).' pages: '.$message);
                }

                return redirect()->route('client.social.accounts.index')
                    ->with('error', 'No '.ucfirst($network).' Pages were found. Reconnect and grant both pages_show_list and business_management so Cerqle can include Pages assigned through a Meta Business Portfolio.');
            }

            $connected = 0;

            foreach ($pages as $page) {
                $pageToken = $page['access_token'] ?? null;
                if (! is_string($pageToken) || $pageToken === '') {
                    Log::warning('Social OAuth: Page has no access token and was skipped', [
                        'workspace_id' => $wid,
                        'network' => $network,
                        'page_id' => $page['id'] ?? null,
                    ]);

                    continue;
                }

                if ($network === 'instagram') {
                    $igAccount = $page['instagram_business_account'] ?? null;
                    if (! $igAccount) {
                        // This page has no linked Instagram Business account — skip it.
                        continue;
                    }

                    $igName = ! empty($igAccount['username'])
                        ? '@'.$igAccount['username']
                        : ($igAccount['name'] ?? $page['name']);

                    SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'instagram', 'account_id' => $igAccount['id']],
                        [
                            'name' => $igName,
                            'picture_url' => $igAccount['profile_picture_url'] ?? ($page['picture']['data']['url'] ?? null),
                            'access_token' => $pageToken, // page token is used for IG Graph API calls
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'scopes' => $grantedScopes,
                            'active' => true,
                        ]
                    );
                } else {
                    SocialAccount::updateOrCreate(
                        ['workspace_id' => $wid, 'network' => 'facebook', 'account_id' => $page['id']],
                        [
                            'name' => $page['name'],
                            'picture_url' => $page['picture']['data']['url'] ?? null,
                            'access_token' => $pageToken,
                            'refresh_token' => null,
                            'token_expires_at' => null,
                            'scopes' => $grantedScopes,
                            'active' => true,
                        ]
                    );
                }

                $connected++;
            }

            if ($connected === 0) {
                $message = $network === 'instagram'
                    ? 'No Instagram Business accounts were found linked to your Facebook Pages. Make sure your Instagram account is set to Business type and connected to a Facebook Page.'
                    : 'Facebook Pages were discovered, but Meta did not return a Page access token. Reconnect and grant Page management access.';

                return redirect()->route('client.social.accounts.index')->with('error', $message);
            }

            $message = $connected.' '.ucfirst($network).' account(s) connected.';

            return redirect()->route('client.social.accounts.index')->with('success', $message);
        }

        if (empty($accountInfo['account_id'])) {
            return redirect()->route('client.social.accounts.index')
                ->with('error', ucfirst($network).' connected, but the provider did not return an account identity. Nothing was saved.');
        }

        $identity = ['workspace_id' => $wid, 'network' => $network, 'account_id' => $accountInfo['account_id']];
        $existing = SocialAccount::where($identity)->first();

        SocialAccount::updateOrCreate(
            $identity,
            [
                'name' => $accountInfo['name'],
                'picture_url' => $accountInfo['picture_url'],
                'access_token' => $tokens['access_token'],
                // Google commonly omits refresh_token on a repeat consent. Keep
                // the existing token instead of turning a reconnect into a
                // connection that expires one hour later.
                'refresh_token' => $tokens['refresh_token'] ?? $existing?->refresh_token,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                'scopes' => isset($tokens['scope'])
                    ? preg_split('/[ ,]+/', (string) $tokens['scope'], -1, PREG_SPLIT_NO_EMPTY)
                    : $existing?->scopes,
                'active' => true,
            ]
        );

        return redirect()->route('client.social.accounts.index')->with('success', ucfirst($network).' account connected.');
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        abort_unless((int) $account->workspace_id === $this->workspaceId($request), 403);
        $network = $account->network;
        $account->delete();

        $message = $network === 'linkedin'
            ? 'LinkedIn account disconnected from Cerqle. Your LinkedIn browser session remains signed in; choose “Sign out to use another account” when reconnecting.'
            : 'Account disconnected.';

        return back()->with('success', $message);
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @param  list<string>  $selectedTargetIds
     * @return list<array<string, mixed>>
     */
    private function filterMetaPagesToSelectedTargets(array $pages, array $selectedTargetIds): array
    {
        return array_values(array_filter($pages, function (array $page) use ($selectedTargetIds): bool {
            $pageId = (string) ($page['id'] ?? '');
            $instagramId = (string) ($page['instagram_business_account']['id'] ?? '');

            return ($pageId !== '' && in_array($pageId, $selectedTargetIds, true))
                || ($instagramId !== '' && in_array($instagramId, $selectedTargetIds, true));
        }));
    }
}
