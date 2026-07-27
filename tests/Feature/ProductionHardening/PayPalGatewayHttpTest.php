<?php

namespace Tests\Feature\ProductionHardening;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\PayPalGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalGatewayHttpTest extends TestCase
{
    public function test_checkout_sends_paypal_required_payment_preferences(): void
    {
        Http::fake([
            'https://api-m.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'access-token',
                'token_type' => 'Bearer',
            ]),
            'https://api-m.paypal.com/v1/catalogs/products' => Http::response([
                'id' => 'PROD-123',
            ], 201),
            'https://api-m.paypal.com/v1/billing/plans' => Http::response([
                'id' => 'PLAN-123',
            ], 201),
            'https://api-m.paypal.com/v1/billing/subscriptions' => Http::response([
                'id' => 'SUB-123',
                'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.paypal.com/approve/SUB-123'],
                ],
            ], 201),
        ]);

        $user = new User(['email' => 'buyer@example.com']);
        $user->id = 7;

        $plan = new Plan([
            'name' => 'Pro',
            'monthly_price_cents' => 1200,
            'currency_code' => 'USD',
        ]);
        $plan->id = 3;

        $result = (new PayPalGateway(
            'client-id',
            'client-secret',
            false,
            'https://cerqle.ai/app/billing?checkout=success',
            'https://cerqle.ai/app/pricing?checkout=canceled',
            'webhook-id',
        ))->createCheckout($user, $plan, 'month');

        $this->assertSame('https://www.paypal.com/approve/SUB-123', $result['url']);
        $this->assertSame('SUB-123', $result['subscription_id']);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api-m.paypal.com/v1/billing/plans') {
                return false;
            }

            return $request['payment_preferences'] === [
                'auto_bill_outstanding' => true,
                'payment_failure_threshold' => 3,
            ];
        });
    }
}
