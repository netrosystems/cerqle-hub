<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ClientAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientAccess
{
    private const SHELL_ROUTES = [
        'client.dashboard',
        'client.subscription.*',
        'client.pricing',
        'client.checkout.store',
        'client.coupon.*',
        'client.billing.*',
        'client.settings.*',
        'client.profile.*',
        'client.onboarding.*',
        'client.workspaces.index',
        'client.workspaces.switch',
        'api.v1.mobile.workspaces.select',
    ];

    public function __construct(private readonly ClientAccessService $access) {}

    public function handle(Request $request, Closure $next, string $mode = 'auto'): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $request->routeIs(...self::SHELL_ROUTES)) {
            return $next($request);
        }

        $state = $this->access->state($user);
        if ($state === ClientAccessService::ACTIVE) {
            return $next($request);
        }

        if ($state === ClientAccessService::EXPIRED && $mode !== 'write' && $request->isMethodSafe()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            $verificationRequired = $state === ClientAccessService::UNVERIFIED;

            return response()->json([
                'message' => $verificationRequired
                    ? __('Verify your email address to use Cerqle features.')
                    : __('An active subscription plan is required.'),
                'code' => $verificationRequired ? 'email_verification_required' : 'subscription_required',
                'access_state' => $state,
                'pricing_url' => route('client.pricing'),
            ], $verificationRequired ? 403 : 402);
        }

        if ($state === ClientAccessService::UNVERIFIED) {
            return redirect()->route('client.dashboard')
                ->with('error', __('Verify your email address to continue.'));
        }

        if ($state === ClientAccessService::NO_PLAN) {
            return redirect()->route('client.pricing')
                ->with('error', __('Select a plan before using Cerqle features.'));
        }

        return redirect()->route('client.dashboard')
            ->with('error', __('Your subscription is inactive. Cerqle is currently read-only.'));
    }
}
