<?php

namespace App\Modules\Inbox\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves website visitor IP addresses into geographical information
 * (Country, ISO Country Code, City, Latitude, Longitude, and Timezone).
 *
 * Uses local 30-day caching to keep overhead near-zero and respect rate limits.
 * Fallbacks gracefully for local/private network addresses (127.0.0.1, ::1, 192.168.*, etc.).
 */
class WebchatGeoService
{
    /**
     * Resolve geographical metadata for an IP address.
     *
     * @return array{
     *     country: ?string,
     *     country_code: ?string,
     *     city: ?string,
     *     region: ?string,
     *     lat: ?float,
     *     lon: ?float,
     *     timezone: ?string
     * }
     */
    public function resolve(string $ip): array
    {
        $ip = trim($ip);

        if ($this->isLocalOrPrivateIp($ip)) {
            return $this->localFallbackGeo();
        }

        $cacheKey = "webchat:geo:{$ip}";

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($ip) {
            try {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get("http://ip-api.com/json/{$ip}?fields=status,country,countryCode,regionName,city,lat,lon,timezone");

                if ($response->successful()) {
                    $data = $response->json();
                    if (($data['status'] ?? '') === 'success') {
                        return [
                            'country' => $data['country'] ?? null,
                            'country_code' => isset($data['countryCode']) ? strtoupper((string) $data['countryCode']) : null,
                            'city' => $data['city'] ?? null,
                            'region' => $data['regionName'] ?? null,
                            'lat' => isset($data['lat']) ? (float) $data['lat'] : null,
                            'lon' => isset($data['lon']) ? (float) $data['lon'] : null,
                            'timezone' => $data['timezone'] ?? null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("WebchatGeoService resolution failed for IP [{$ip}]: {$e->getMessage()}");
            }

            return [
                'country' => null,
                'country_code' => null,
                'city' => null,
                'region' => null,
                'lat' => null,
                'lon' => null,
                'timezone' => null,
            ];
        });
    }

    /**
     * Check if an IP address is loopback, local, or private.
     */
    public function isLocalOrPrivateIp(?string $ip): bool
    {
        if (! $ip || $ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return true;
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Default fallback for local testing & demos.
     *
     * @return array{
     *     country: string,
     *     country_code: string,
     *     city: string,
     *     region: string,
     *     lat: float,
     *     lon: float,
     *     timezone: string
     * }
     */
    private function localFallbackGeo(): array
    {
        return [
            'country' => 'Bangladesh',
            'country_code' => 'BD',
            'city' => 'Dhaka',
            'region' => 'Dhaka Division',
            'lat' => 23.8103,
            'lon' => 90.4125,
            'timezone' => 'Asia/Dhaka',
        ];
    }
}
