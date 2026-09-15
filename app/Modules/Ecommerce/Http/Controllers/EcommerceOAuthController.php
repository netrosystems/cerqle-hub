<?php

namespace App\Modules\Ecommerce\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\OAuth\EcommerceOAuthManager;
use App\Modules\Ecommerce\Services\StoreConnector;
use App\Modules\Ecommerce\Services\StoreUrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class EcommerceOAuthController extends Controller
{
    public function __construct(
        private readonly EcommerceOAuthManager $oauth,
        private readonly StoreConnector $connector,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    private function index(): string
    {
        return route('client.ecommerce.stores.index');
    }

    /**
     * Begin an OAuth connect. Shopify/Woo are initiated here; BigCommerce is
     * installed from the merchant's BigCommerce control panel (its callback does the work).
     */
    public function connect(Request $request, string $platform): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        if ($platform === 'shopify') {
            $shop = StoreConnector::normalizeDomain('shopify', (string) $request->query('shop', ''));
            if ($error = StoreUrlGuard::validate('shopify', $shop)) {
                return redirect($this->index())->with('error', $error);
            }

            $state = Str::random(40);
            Session::put('ecom_oauth', ['state' => $state, 'shop' => $shop, 'workspace' => $wid, 'user' => $request->user()->id, 'expires_at' => now()->addMinutes(10)->timestamp]);

            try {
                $url = $this->oauth->shopifyAuthUrl($shop, $state, route('client.ecommerce.oauth.shopify.callback'));
            } catch (\RuntimeException $e) {
                return redirect($this->index())->with('error', 'Shopify OAuth is not configured by the administrator.');
            }

            return redirect()->away($url);
        }

        if ($platform === 'woocommerce') {
            $storeUrl = StoreConnector::normalizeDomain('woocommerce', (string) $request->query('store_url', ''));
            if ($error = StoreUrlGuard::validate('woocommerce', $storeUrl)) {
                return redirect($this->index())->with('error', $error);
            }

            // A store UUID is public identity, not callback authorization.
            $store = EcommerceStore::firstOrCreate(
                ['workspace_id' => $wid, 'platform' => 'woocommerce', 'domain' => $storeUrl],
                ['name' => 'WooCommerce Store', 'status' => 'pending'],
            );

            $attemptToken = Str::random(64);
            DB::table('ecommerce_oauth_attempts')->insert([
                'token_hash' => hash('sha256', $attemptToken),
                'user_id' => $request->user()->id,
                'workspace_id' => $wid,
                'store_id' => $store->id,
                'expires_at' => now()->addMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $url = $this->oauth->wooAuthUrl(
                $storeUrl,
                $attemptToken,
                route('webhooks.ecommerce.woo_auth'),
                route('client.ecommerce.oauth.woocommerce.return'),
            );

            return redirect()->away($url);
        }

        return redirect($this->index())
            ->with('error', 'BigCommerce connects by installing the app from your BigCommerce control panel.');
    }

    public function shopifyCallback(Request $request): RedirectResponse
    {
        $stored = Session::pull('ecom_oauth', []);
        $shop = StoreConnector::normalizeDomain('shopify', (string) $request->query('shop', ''));
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if (empty($stored['state']) || ! hash_equals($stored['state'], $state) || ($stored['shop'] ?? null) !== $shop) {
            return redirect($this->index())->with('error', 'Invalid OAuth state. Please try connecting again.');
        }
        if (($stored['user'] ?? null) !== $request->user()->id
            || ($stored['expires_at'] ?? 0) <= now()->timestamp
            || ! $request->user()->canAccessWorkspace((int) ($stored['workspace'] ?? 0))) {
            return redirect($this->index())->with('error', 'OAuth access expired. Please connect again.');
        }
        if ($error = StoreUrlGuard::validate('shopify', $shop)) {
            return redirect($this->index())->with('error', $error);
        }
        if (! $this->oauth->shopifyVerifyHmac($request->query())) {
            return redirect($this->index())->with('error', 'Shopify request signature could not be verified.');
        }

        $token = $code ? $this->oauth->shopifyExchange($shop, $code) : null;
        if (! $token) {
            return redirect($this->index())->with('error', 'Failed to obtain Shopify access token.');
        }

        $result = $this->connector->connect((int) $stored['workspace'], 'shopify', $shop, ['access_token' => $token]);

        return redirect($this->index())->with($result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Shopify store connected.' : ('Connected but: '.$result['message']));
    }

    public function bigcommerceCallback(Request $request): RedirectResponse
    {
        $code = (string) $request->query('code', '');
        $scope = (string) $request->query('scope', '');
        $context = (string) $request->query('context', ''); // stores/{hash}

        if ($code === '' || $context === '') {
            return redirect($this->index())->with('error', 'BigCommerce did not return an authorization code.');
        }

        $result = $this->oauth->bigcommerceExchange($code, $scope, $context, route('client.ecommerce.oauth.bigcommerce.callback'));
        if (! $result) {
            return redirect($this->index())->with('error', 'Failed to obtain BigCommerce access token.');
        }

        $connect = $this->connector->connect(
            $this->workspaceId($request),
            'bigcommerce',
            $result['store_hash'],
            ['access_token' => $result['access_token']],
        );

        return redirect($this->index())->with($connect['ok'] ? 'success' : 'error',
            $connect['ok'] ? 'BigCommerce store connected.' : ('Connected but: '.$connect['message']));
    }

    /** Browser landing after the merchant approves in WooCommerce. */
    public function woocommerceReturn(): RedirectResponse
    {
        return redirect($this->index())->with('success', 'WooCommerce authorization complete. Finishing connection…');
    }

    /**
     * Server-to-server callback: WooCommerce POSTs the API keys here. Public route
     * (no auth/CSRF); the pending store is identified by its uuid (our user_id).
     */
    public function woocommerceCallback(Request $request): JsonResponse
    {
        $attempt = DB::transaction(function () use ($request) {
            $query = DB::table('ecommerce_oauth_attempts');
            $attempt = (clone $query)->where('token_hash', hash('sha256', (string) $request->input('user_id')))
                ->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $attempt) {
                return null;
            }
            $query->where('id', $attempt->id)->update(['consumed_at' => now(), 'updated_at' => now()]);

            return $attempt;
        });
        $actor = $attempt ? User::find($attempt->user_id) : null;
        $store = $attempt && $actor?->canAuthenticate() && $actor->canAccessWorkspace((int) $attempt->workspace_id)
            ? EcommerceStore::whereKey($attempt->store_id)->where('workspace_id', $attempt->workspace_id)->where('platform', 'woocommerce')->first()
            : null;

        $key = (string) $request->input('consumer_key', '');
        $secret = (string) $request->input('consumer_secret', '');

        if (! $store || $key === '' || $secret === '') {
            Log::warning('ecommerce.oauth.woo.invalid_callback');

            return response()->json(['status' => 'error'], 400);
        }

        $result = $this->connector->connect(
            $store->workspace_id,
            'woocommerce',
            $store->domain,
            ['consumer_key' => $key, 'consumer_secret' => $secret],
        );

        if (! $result['ok']) {
            Log::warning('ecommerce.oauth.woo.connect_failed', ['store' => $store->id, 'message' => $result['message']]);

            // WooCommerce uses this response to decide whether its credential
            // hand-off succeeded. Returning HTTP 200 here made the Woo UI report
            // success even when our authenticated API test failed.
            return response()->json(['status' => 'error', 'message' => $result['message']], 502);
        }

        return response()->json(['status' => 'ok']);
    }
}
