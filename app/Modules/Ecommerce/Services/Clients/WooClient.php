<?php

namespace App\Modules\Ecommerce\Services\Clients;

use App\Modules\Ecommerce\Services\Credentials\StoreCredentials;
use App\Services\PublicHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WooClient implements EcommerceClientInterface
{
    /** WooCommerce REST API webhook topics. */
    public const WEBHOOK_TOPICS = ['order.created', 'order.updated', 'product.updated'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly StoreCredentials $credentials,
        private readonly ?string $webhookSecret = null,
    ) {}

    private function http(): PendingRequest
    {
        $base = rtrim($this->baseUrl, '/').'/wp-json/wc/v3';

        return Http::withBasicAuth($this->credentials->consumerKey(), $this->credentials->consumerSecret())
            ->acceptJson()
            ->timeout(15)
            ->baseUrl($base);
    }

    /** @param array<string, mixed> $data */
    private function request(string $method, string $path, array $data = []): Response
    {
        $url = rtrim($this->baseUrl, '/').'/wp-json/wc/v3'.$path;

        return app(PublicHttpClient::class)->send($this->http(), $method, $url,
            $method === 'GET' ? ['query' => $data] : ['json' => $data], maxBytes: 10485760);
    }

    public function testConnection(): array
    {
        try {
            // products?per_page=1 is the cheapest authenticated read available on every Woo store.
            $resp = $this->request('GET', '/products', ['per_page' => 1]);
            if ($resp->successful()) {
                return ['ok' => true, 'message' => 'Connected to '.$this->baseUrl];
            }

            return ['ok' => false, 'message' => $resp->json()['message'] ?? 'WooCommerce rejected the API keys.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function registerWebhooks(string $callbackUrl): array
    {
        $created = 0;
        $failures = [];
        try {
            foreach (self::WEBHOOK_TOPICS as $topic) {
                $resp = $this->request('POST', '/webhooks', [
                    'name' => 'Cerqle '.$topic,
                    'topic' => $topic,
                    'delivery_url' => $callbackUrl,
                    'secret' => $this->webhookSecret,
                ]);
                if ($resp->status() === 201) {
                    $created++;
                } else {
                    $failures[] = $topic.' (HTTP '.$resp->status().')';
                }
            }

            return $failures === []
                ? ['ok' => true, 'message' => "Registered {$created} webhook(s)."]
                : ['ok' => false, 'message' => 'Webhook registration failed for: '.implode(', ', $failures)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function deregisterWebhooks(string $callbackUrl): array
    {
        try {
            $resp = $this->request('GET', '/webhooks', ['per_page' => 100]);
            $deleted = 0;
            foreach ($resp->json() ?? [] as $hook) {
                if (($hook['delivery_url'] ?? '') === $callbackUrl && isset($hook['id'])) {
                    $this->request('DELETE', "/webhooks/{$hook['id']}", ['force' => true]);
                    $deleted++;
                }
            }

            return ['ok' => true, 'message' => "Removed {$deleted} webhook(s)."];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchCustomers(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->request('GET', '/customers', ['per_page' => 100, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("WooCommerce customers fetch failed (HTTP {$resp->status()}).");
        }

        $customers = $resp->json() ?? [];
        $totalPages = (int) ($resp->header('X-WP-TotalPages') ?: 1);
        $next = $page < $totalPages ? (string) ($page + 1) : null;

        return ['customers' => $customers, 'next' => $next];
    }

    public function fetchOrder(string $externalId): ?array
    {
        $resp = $this->request('GET', '/orders/'.rawurlencode($externalId));

        return $resp->successful() ? $resp->json() : null;
    }

    public function fetchOrders(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->request('GET', '/orders', ['per_page' => 100, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("WooCommerce orders fetch failed (HTTP {$resp->status()}).");
        }

        $orders = $resp->json() ?? [];
        $totalPages = (int) ($resp->header('X-WP-TotalPages') ?: 1);
        $next = $page < $totalPages ? (string) ($page + 1) : null;

        return ['orders' => $orders, 'next' => $next];
    }

    public function fetchRecentOrdersForContact(?string $email, ?string $phone, int $limit = 5): array
    {
        if (! $email) {
            return [];
        }
        $resp = $this->request('GET', '/orders', ['search' => $email, 'per_page' => $limit]);

        return $resp->successful() ? ($resp->json() ?? []) : [];
    }

    public function fulfillOrder(string $externalId, ?string $trackingNumber = null, ?string $trackingUrl = null): array
    {
        try {
            $payload = ['status' => 'completed'];
            if ($trackingNumber) {
                // Surfaces in order meta; shipment-tracking plugins read these keys.
                $payload['meta_data'] = [
                    ['key' => '_tracking_number', 'value' => $trackingNumber],
                    ['key' => '_tracking_url', 'value' => $trackingUrl ?? ''],
                ];
            }
            $resp = $this->request('PUT', '/orders/'.rawurlencode($externalId), $payload);

            return $resp->successful()
                ? ['ok' => true, 'message' => 'Order marked completed.']
                : ['ok' => false, 'message' => $resp->json()['message'] ?? 'WooCommerce rejected the update.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function fetchProducts(?string $cursor = null): array
    {
        $page = $cursor ? (int) $cursor : 1;
        $resp = $this->request('GET', '/products', ['per_page' => 100, 'page' => $page]);

        if (! $resp->successful()) {
            throw new \RuntimeException("WooCommerce products fetch failed (HTTP {$resp->status()}).");
        }

        $products = $resp->json() ?? [];
        $totalPages = (int) ($resp->header('X-WP-TotalPages') ?: 1);

        return [
            'products' => $products,
            'next' => $page < $totalPages ? (string) ($page + 1) : null,
        ];
    }
}
