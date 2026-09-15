<?php

namespace App\Modules\Ecommerce\Services;

use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;

class StoreConnectionTester
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function test(EcommerceStore $store, bool $persist = true): array
    {
        try {
            $result = StoreClientFactory::for($store)->testConnection();
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        $store->fill([
            'last_tested_at' => now(),
            'last_test_status' => $result['ok'] ? 'ok' : 'fail',
            'last_test_message' => $result['message'],
            'status' => $result['ok'] ? 'connected' : 'error',
            'external_meta' => array_merge($store->external_meta ?? [], $result['meta'] ?? []),
        ]);
        if ($persist) {
            $store->save();
        }

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }
}
