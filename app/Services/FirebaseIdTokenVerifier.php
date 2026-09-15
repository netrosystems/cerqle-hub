<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FirebaseIdTokenVerifier
{
    public const CERTIFICATES_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    /** @return array<string, mixed>|null */
    public function verify(string $token, string $projectId): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3 || strlen($token) > 16384) {
                return null;
            }
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $kid = $header['kid'] ?? null;
            if (($header['alg'] ?? null) !== 'RS256' || ! is_string($kid) || strlen($kid) > 256) {
                return null;
            }
            // Only Google's fixed certificate endpoint is used. Unknown keys fail closed
            // until the bounded cache expires; hostile tokens cannot force fetch loops.
            $certificates = Cache::remember('auth:firebase:certificates', 300, function (): array {
                $response = Http::timeout(10)->withoutRedirecting()->get(self::CERTIFICATES_URL)->throw();

                return $response->json();
            });
            if (! isset($certificates[$kid]) || ! is_string($certificates[$kid])) {
                return null;
            }
            $claims = (array) JWT::decode($token, new Key($certificates[$kid], 'RS256'));
            $now = time();
            if (($claims['aud'] ?? null) !== $projectId
                || ($claims['iss'] ?? null) !== "https://securetoken.google.com/{$projectId}"
                || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 128
                || ! is_numeric($claims['exp'] ?? null) || $claims['exp'] <= $now
                || ! is_numeric($claims['iat'] ?? null) || $claims['iat'] > $now
                || ! is_numeric($claims['auth_time'] ?? null) || $claims['auth_time'] > $now
                || ($claims['email_verified'] ?? false) !== true
                || ! is_string($claims['email'] ?? null) || ! filter_var($claims['email'], FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            return $claims;
        } catch (\Throwable) {
            return null;
        }
    }
}
