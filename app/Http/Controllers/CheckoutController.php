<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /**
     * Start checkout for a plan with the selected gateway and billing cycle.
     */
    public function store(Request $request): RedirectResponse|Response
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['required', 'string', Rule::in(['month', 'year'])],
            'gateway' => ['required', 'string', Rule::in(BillingGatewayRegistry::SUPPORTED_GATEWAYS)],
        ]);

        $plan = Plan::where('enabled', true)->findOrFail($validated['plan_id']);
        $gateway = $this->gateways->get($validated['gateway']);

        if (! $gateway || ! $gateway->isConfigured()) {
            return back()->with('error', __('That payment gateway is not configured.'));
        }

        $result = $gateway->createCheckout($request->user(), $plan, $validated['billing_cycle']);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        // Most gateways return a hosted redirect URL.
        if (isset($result['url'])) {
            return Inertia::location($result['url']);
        }

        // The legacy SDK checkout branch (used by Cashfree) is intentionally
        // disabled while Cerqle supports only Stripe, PayPal, and Paddle.

        return back()->with('error', __('Checkout could not be started.'));
    }
}
