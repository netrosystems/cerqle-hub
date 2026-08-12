<?php

namespace App\Modules\Social\Services\OAuth;

use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

/**
 * Centralized OAuth helper for all social networks.
 *
 * Each network's OAuth credentials (client_id, client_secret) are retrieved via
 * the CredentialResolver (workspace override → system default stored in integration_configs).
 *
 * Supported networks:
 *   facebook  – FB Login / Graph API
 *   instagram – Instagram Basic Display / Graph API (via FB App)
 *   linkedin  – LinkedIn OAuth 2.0
 *   youtube   – Google OAuth 2.0
 *   tiktok    – TikTok Content Posting API OAuth 2.0
 */
class OAuthManager
{
    private const META_GRAPH_VERSION = 'v25.0';

    private const META_GRAPH_BASE = 'https://graph.facebook.com/'.self::META_GRAPH_VERSION;

    public function __construct(private readonly CredentialResolver $credentials) {}

    public function getAuthUrl(string $network, int $workspaceId, string $callbackUrl): string
    {
        $creds = $this->credentials->oauth($network);

        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'facebook', 'instagram' => $this->facebookAuthUrl($creds, $callbackUrl, $network),
            'linkedin' => $this->linkedinAuthUrl($creds, $callbackUrl),
            'youtube' => $this->googleAuthUrl($creds, $callbackUrl),
            'tiktok' => $this->tiktokAuthUrl($creds, $callbackUrl),
            default => throw new \InvalidArgumentException("Unsupported network: {$network}"),
        };
    }

    /**
     * @param  array  $storedState  The already-validated session state data (passed in by the controller
     *                              after it verified the `state` query param — avoids re-reading the session).
     */
    public function exchangeCode(string $network, string $code, string $callbackUrl, array $storedState = []): array
    {
        $creds = $this->credentials->oauth($network);

        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'facebook', 'instagram' => $this->facebookExchange($creds, $code, $callbackUrl),
            'linkedin' => $this->linkedinExchange($creds, $code, $callbackUrl),
            'youtube' => $this->googleExchange($creds, $code, $callbackUrl),
            'tiktok' => $this->tiktokExchange($creds, $code, $callbackUrl),
            default => throw new \InvalidArgumentException("Unsupported network: {$network}"),
        };
    }

    /**
     * Refresh an access token using a refresh_token.
     * Returns ['access_token', 'refresh_token' (if rotated), 'expires_in'] or throws on failure.
     */
    public function refresh(string $network, string $refreshToken): array
    {
        $creds = $this->credentials->oauth($network);
        if ($creds === null) {
            throw new \RuntimeException("OAuth credentials for [{$network}] are not configured or disabled.");
        }

        return match ($network) {
            'youtube' => $this->googleRefresh($creds, $refreshToken),
            'tiktok' => $this->tiktokRefresh($creds, $refreshToken),
            'linkedin' => $this->linkedinRefresh($creds, $refreshToken),
            'facebook',
            'instagram' => throw new \RuntimeException('Facebook/Instagram tokens are long-lived; use token extension instead.'),
            default => throw new \InvalidArgumentException("Unsupported network for refresh: {$network}"),
        };
    }

    // ── Facebook / Instagram ────────────────────────────────────────────────

    private function facebookAuthUrl($creds, string $redirect, string $network): string
    {
        $scopes = $network === 'instagram'
            ? 'instagram_basic,instagram_content_publish,pages_show_list,business_management'
            : 'pages_manage_posts,pages_read_engagement,pages_show_list,business_management';
        $state = $this->storeState(['network' => $network]);

        return 'https://www.facebook.com/'.self::META_GRAPH_VERSION.'/dialog/oauth?'.http_build_query([
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'scope' => $scopes,
            'state' => $state,
            'response_type' => 'code',
        ]);
    }

    private function facebookExchange($creds, string $code, string $redirect): array
    {
        $shortResponse = Http::timeout(15)->get(self::META_GRAPH_BASE.'/oauth/access_token', [
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'redirect_uri' => $redirect,
            'code' => $code,
        ]);
        $this->assertSuccessful($shortResponse, 'Meta authorization-code exchange');

        $shortToken = $shortResponse->json('access_token');
        if (! is_string($shortToken) || $shortToken === '') {
            throw new \RuntimeException('Meta authorization-code exchange returned no access token.');
        }

        // Page tokens inherit the lifetime of the user token used to discover
        // them. Extend the short-lived login token before requesting Pages so a
        // successful connection does not silently stop working a few hours later.
        $longResponse = Http::timeout(15)->get(self::META_GRAPH_BASE.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'fb_exchange_token' => $shortToken,
        ]);
        $this->assertSuccessful($longResponse, 'Meta long-lived token exchange');

        $token = $longResponse->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('Meta long-lived token exchange returned no access token.');
        }

        return [
            'access_token' => $token,
            'expires_in' => $longResponse->json('expires_in'),
            'token_type' => $longResponse->json('token_type', 'bearer'),
        ];
    }

    // ── LinkedIn ────────────────────────────────────────────────────────────

    private function linkedinAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'linkedin']);

        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'scope' => 'openid profile email w_member_social',
            'state' => $state,
        ]);
    }

    private function linkedinExchange($creds, string $code, string $redirect): array
    {
        $response = Http::asForm()->timeout(15)->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
        ]);
        $this->assertSuccessful($response, 'LinkedIn token exchange');
        $res = $response->json();

        return [
            'access_token' => $res['access_token'] ?? null,
            'refresh_token' => $res['refresh_token'] ?? null,
            'expires_in' => $res['expires_in'] ?? null,
            'scope' => $res['scope'] ?? null,
        ];
    }

    // ── Google / YouTube ────────────────────────────────────────────────────

    private function googleAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'youtube']);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            // The full YouTube scope is required for optional playlist
            // placement in addition to uploads, thumbnails, and channel reads.
            'scope' => 'https://www.googleapis.com/auth/youtube',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    private function googleExchange($creds, string $code, string $redirect): array
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'redirect_uri' => $redirect,
            'grant_type' => 'authorization_code',
        ]);
        $this->assertSuccessful($response, 'Google token exchange');
        $res = $response->json();

        return [
            'access_token' => $res['access_token'] ?? null,
            'refresh_token' => $res['refresh_token'] ?? null,
            'expires_in' => $res['expires_in'] ?? null,
            'scope' => $res['scope'] ?? null,
        ];
    }

    // ── TikTok ──────────────────────────────────────────────────────────────

    private function tiktokAuthUrl($creds, string $redirect): string
    {
        $state = $this->storeState(['network' => 'tiktok']);

        return 'https://www.tiktok.com/v2/auth/authorize?'.http_build_query([
            'client_key' => $creds->clientId() ?? '',
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'user.info.basic,video.publish',
            'state' => $state,
        ]);
    }

    private function tiktokExchange($creds, string $code, string $redirect): array
    {
        $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
        ]);
        $this->assertSuccessful($response, 'TikTok token exchange');
        $res = $response->json();

        return [
            'access_token' => $res['access_token'] ?? null,
            'refresh_token' => $res['refresh_token'] ?? null,
            'expires_in' => $res['expires_in'] ?? null,
            'refresh_expires_in' => $res['refresh_expires_in'] ?? null,
            'open_id' => $res['open_id'] ?? null,
            'scope' => $res['scope'] ?? null,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function googleRefresh($creds, string $refreshToken): array
    {
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        $this->assertSuccessful($response, 'Google token refresh');
        $res = $response->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('Google token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $refreshToken, 'expires_in' => $res['expires_in'] ?? 3600];
    }

    private function tiktokRefresh($creds, string $refreshToken): array
    {
        $response = Http::asForm()->timeout(15)->post('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
        $this->assertSuccessful($response, 'TikTok token refresh');
        $res = $response->json();

        $token = $res['access_token'] ?? null;
        if (! $token) {
            throw new \RuntimeException('TikTok token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $token, 'refresh_token' => $res['refresh_token'] ?? $refreshToken, 'expires_in' => $res['expires_in'] ?? null];
    }

    private function linkedinRefresh($creds, string $refreshToken): array
    {
        $response = Http::asForm()->timeout(15)->post('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $creds->clientId() ?? '',
            'client_secret' => $creds->clientSecret() ?? '',
        ]);
        $this->assertSuccessful($response, 'LinkedIn token refresh');
        $res = $response->json();

        if (empty($res['access_token'])) {
            throw new \RuntimeException('LinkedIn token refresh failed: '.json_encode($res));
        }

        return ['access_token' => $res['access_token'], 'refresh_token' => $res['refresh_token'] ?? $refreshToken, 'expires_in' => $res['expires_in'] ?? null];
    }

    private function storeState(array $data): string
    {
        $state = bin2hex(random_bytes(16));
        Session::put('social_oauth_state', array_merge($data, ['state' => $state]));

        return $state;
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message')
            ?? $response->json('error_description')
            ?? $response->json('message')
            ?? $response->body();

        throw new \RuntimeException($operation.' failed (HTTP '.$response->status().'): '.mb_substr((string) $message, 0, 500));
    }
}
