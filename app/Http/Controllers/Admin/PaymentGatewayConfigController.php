<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConfig;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaymentGatewayConfigController extends Controller
{
    /** Gateways the admin panel can configure. */
    /** Display labels (falls back to ucfirst for anything missing). */
    private const LABELS = [
        'stripe' => 'Stripe',
        'paypal' => 'PayPal',
        'paddle' => 'Paddle',
    ];

    public function index(): Response
    {
        $gateways = BillingGatewayRegistry::SUPPORTED_GATEWAYS;
        $configs = PaymentGatewayConfig::whereIn('gateway', $gateways)->get()->keyBy('gateway');

        $list = [];
        foreach ($gateways as $key) {
            $config = $configs->get($key);
            $list[] = [
                'gateway' => $key,
                'name' => self::LABELS[$key] ?? ucfirst($key),
                'enabled' => $config?->enabled ?? false,
                'test_mode' => $config?->test_mode ?? true,
                'configured' => $config?->hasActiveCredentials() ?? false,
            ];
        }

        return Inertia::render('Admin/PaymentGateways/Index', [
            'gateways' => $list,
        ]);
    }

    /**
     * Get one gateway config for editing (credentials masked for secrets).
     */
    public function show(string $gateway): JsonResponse
    {
        $this->validateGateway($gateway);
        $config = PaymentGatewayConfig::firstOrCreate(
            ['gateway' => $gateway],
            [
                'test_mode' => true,
                'enabled' => false,
                'credentials' => [
                    'test' => $this->defaultCredentialKeys($gateway),
                    'live' => $this->defaultCredentialKeys($gateway),
                ],
            ]
        );

        $credentials = $config->credentials ?? [];
        $test = $credentials['test'] ?? [];
        $live = $credentials['live'] ?? [];

        $data = [
            'gateway' => $config->gateway,
            'name' => self::LABELS[$config->gateway] ?? ucfirst($config->gateway),
            'test_mode' => $config->test_mode,
            'enabled' => $config->enabled,
            'test_publishable_key' => $test['publishable_key'] ?? '',
            'test_secret_key' => ($test['secret_key'] ?? '') !== '' ? '••••••••' : '',
            // PayPal verifies incoming events with the webhook *ID*, not a
            // signing secret. It is safe and much less confusing to show it.
            'test_webhook_secret' => $config->gateway === 'paypal'
                ? ($test['webhook_secret'] ?? '')
                : (($test['webhook_secret'] ?? '') !== '' ? '••••••••' : ''),
            'live_publishable_key' => $live['publishable_key'] ?? '',
            'live_secret_key' => ($live['secret_key'] ?? '') !== '' ? '••••••••' : '',
            'live_webhook_secret' => $config->gateway === 'paypal'
                ? ($live['webhook_secret'] ?? '')
                : (($live['webhook_secret'] ?? '') !== '' ? '••••••••' : ''),
        ];

        return response()->json($data);
    }

    public function update(Request $request, string $gateway): RedirectResponse
    {
        $this->validateGateway($gateway);
        $config = PaymentGatewayConfig::firstOrCreate(
            ['gateway' => $gateway],
            [
                'test_mode' => true,
                'enabled' => false,
                'credentials' => [
                    'test' => $this->defaultCredentialKeys($gateway),
                    'live' => $this->defaultCredentialKeys($gateway),
                ],
            ]
        );

        $rules = [
            'test_mode' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'test_publishable_key' => ['nullable', 'string', 'max:512'],
            'test_secret_key' => ['nullable', 'string', 'max:512'],
            'test_webhook_secret' => ['nullable', 'string', 'max:512'],
            'live_publishable_key' => ['nullable', 'string', 'max:512'],
            'live_secret_key' => ['nullable', 'string', 'max:512'],
            'live_webhook_secret' => ['nullable', 'string', 'max:512'],
        ];

        $validated = $request->validate($rules);

        $existing = $config->credentials ?? [
            'test' => $this->defaultCredentialKeys($gateway),
            'live' => $this->defaultCredentialKeys($gateway),
        ];

        $credentialKeys = ['publishable_key', 'secret_key', 'webhook_secret'];
        $test = $existing['test'] ?? [];
        $live = $existing['live'] ?? [];

        foreach (['test', 'live'] as $mode) {
            $prefix = $mode.'_';
            foreach ($credentialKeys as $k) {
                $field = $prefix.$k;
                $v = $validated[$field] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                if (preg_match('/^•+$/', (string) $v)) {
                    continue;
                }
                if ($mode === 'test') {
                    $test[$k] = $v;
                } else {
                    $live[$k] = $v;
                }
            }
        }

        if (! empty($validated['enabled'])) {
            $mode = (bool) $validated['test_mode'] ? 'test' : 'live';
            $active = $mode === 'test' ? $test : $live;
            $required = $gateway === 'paypal'
                ? ['publishable_key', 'secret_key']
                : ['secret_key', 'webhook_secret'];
            $labels = [
                'publishable_key' => $gateway === 'paypal' ? 'Client ID' : 'Publishable key',
                'secret_key' => $gateway === 'paypal' ? 'Client secret' : 'Secret key',
                'webhook_secret' => $gateway === 'paypal' ? 'PayPal Webhook ID' : 'Webhook signing secret',
            ];

            $messages = [];
            foreach ($required as $key) {
                if (! filled($active[$key] ?? null)) {
                    $messages[$mode.'_'.$key] = $labels[$key].' is required before enabling '.$gateway.'.';
                }
            }
            if ($messages !== []) {
                throw ValidationException::withMessages($messages);
            }
        }

        $config->update([
            'test_mode' => (bool) $validated['test_mode'],
            'enabled' => (bool) $validated['enabled'],
            'credentials' => ['test' => $test, 'live' => $live],
        ]);

        return back()->with('success', __('Payment gateway updated.'));
    }

    private function validateGateway(string $gateway): void
    {
        if (! in_array($gateway, BillingGatewayRegistry::SUPPORTED_GATEWAYS, true)) {
            abort(404);
        }
    }

    private function defaultCredentialKeys(string $gateway): array
    {
        return ['publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''];
    }
}
