<?php

namespace App\Http\Controllers\Client;

use App\Contracts\AddonBillingGatewayInterface;
use App\Http\Controllers\Controller;
use App\Models\ClientAddonSubscription;
use App\Services\AddonEntitlementService;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AddonController extends Controller
{
    public function __construct(
        private AddonEntitlementService $entitlements,
        private BillingGatewayRegistry $gateways
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $addon = config('addons.'.AddonEntitlementService::DEVELOPER_TOOLS);

        $sessionId = $request->query('session_id');
        if ($sessionId) {
            $gateway = $this->gateways->get('stripe');
            if ($gateway instanceof AddonBillingGatewayInterface) {
                $gateway->fulfillAddonCheckout($sessionId);
            }
        }

        $paypalSubscriptionId = $request->query('subscription_id');
        if ($paypalSubscriptionId) {
            $gateway = $this->gateways->get('paypal');
            if ($gateway instanceof AddonBillingGatewayInterface) {
                $gateway->fulfillAddonCheckout((string) $paypalSubscriptionId);
            }
        }

        $subscription = $this->entitlements->subscriptionFor(
            $user,
            AddonEntitlementService::DEVELOPER_TOOLS
        );

        $availableGateways = collect($this->gateways->listForFrontend())
            ->filter(function (array $gateway) use ($addon) {
                if (! $gateway['configured']) {
                    return false;
                }

                return $gateway['key'] !== 'paddle' || filled($addon['paddle_price_id'] ?? null);
            })
            ->values()
            ->all();

        return Inertia::render('client/Addons/Index', [
            'addon' => [
                'key' => $addon['key'],
                'name' => $addon['name'],
                'description' => $addon['description'],
                'price_cents' => $addon['price_cents'],
                'currency' => $addon['currency'],
                'interval' => $addon['interval'],
            ],
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'gateway' => $subscription->gateway,
                'renews_at' => $subscription->renews_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'active' => $subscription->grantsAccess(),
            ] : null,
            'gateways' => $availableGateways,
            'can_manage' => $user->isClientAdministrator(),
        ]);
    }

    public function checkout(Request $request): mixed
    {
        $user = $request->user();
        abort_unless($user->isClientAdministrator() && $user->client_id, 403);

        $validated = $request->validate([
            'gateway' => ['required', 'string', Rule::in(BillingGatewayRegistry::SUPPORTED_GATEWAYS)],
        ]);

        $addon = config('addons.'.AddonEntitlementService::DEVELOPER_TOOLS);
        $existing = $this->entitlements->subscriptionFor($user, $addon['key']);
        if ($existing?->grantsAccess()) {
            return back()->with('error', __('Developer Tools is already active for this client.'));
        }

        $gateway = $this->gateways->get($validated['gateway']);
        if (! $gateway instanceof AddonBillingGatewayInterface || ! $gateway->isConfigured()) {
            return back()->with('error', __('That payment gateway is not configured for add-ons.'));
        }

        $result = $gateway->createAddonCheckout($user, $addon);
        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        $this->entitlements->markPending($user, $addon['key'], $validated['gateway'], [
            'gateway_subscription_id' => $result['subscription_id'] ?? null,
            'checkout_session_id' => $result['session_id'] ?? null,
        ]);

        return Inertia::location($result['url']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isClientAdministrator() && $user->client_id, 403);

        $subscription = $this->entitlements->subscriptionFor(
            $user,
            AddonEntitlementService::DEVELOPER_TOOLS
        );
        if (! $subscription || ! $subscription->gateway) {
            return back()->with('error', __('No Developer Tools subscription was found.'));
        }

        $gateway = $this->gateways->get($subscription->gateway);
        if (! $gateway instanceof AddonBillingGatewayInterface || ! $gateway->cancelAddon($subscription)) {
            return back()->with('error', __('The add-on could not be cancelled. Please contact support.'));
        }

        $subscription->update([
            'status' => ClientAddonSubscription::STATUS_CANCELLED,
            'ends_at' => now(),
            'renews_at' => null,
        ]);

        return back()->with('success', __('Developer Tools has been cancelled.'));
    }
}
