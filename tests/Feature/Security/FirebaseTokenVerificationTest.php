<?php

namespace Tests\Feature\Security;

use App\Services\FirebaseIdTokenVerifier;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FirebaseTokenVerificationTest extends TestCase
{
    #[DataProvider('claims')]
    public function test_signed_tokens_require_exact_firebase_claims(array $overrides, bool $valid): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        Cache::forget('auth:firebase:certificates');
        Http::fake([FirebaseIdTokenVerifier::CERTIFICATES_URL => Http::response(['unit-key' => $public])]);
        $claims = array_replace([
            'iss' => 'https://securetoken.google.com/test-project', 'aud' => 'test-project', 'sub' => 'subject',
            'iat' => time() - 1, 'auth_time' => time() - 1, 'exp' => time() + 300,
            'email' => 'test@example.com', 'email_verified' => true,
        ], $overrides);
        $token = JWT::encode($claims, $private, 'RS256', 'unit-key');
        $verified = app(FirebaseIdTokenVerifier::class)->verify($token, 'test-project');
        $this->assertSame($valid, $verified !== null);
    }

    public static function claims(): array
    {
        return [
            'valid' => [[], true],
            'wrong audience' => [['aud' => 'unrelated'], false],
            'substring audience' => [['aud' => 'prefix-test-project'], false],
            'Google issuer is not Firebase' => [['iss' => 'https://accounts.google.com'], false],
            'unverified email' => [['email_verified' => false], false],
            'invalid email' => [['email' => ['not-an-email']], false],
            'missing subject' => [['sub' => ''], false],
            'expired' => [['exp' => 1], false],
            'future authentication' => [['auth_time' => PHP_INT_MAX], false],
        ];
    }

    public function test_invalid_signature_and_untrusted_algorithm_fail(): void
    {
        Cache::put('auth:firebase:certificates', ['unit-key' => 'not-a-key'], 300);
        $token = JWT::encode(['sub' => 'subject'], str_repeat('secret', 8), 'HS256', 'unit-key');
        $this->assertNull(app(FirebaseIdTokenVerifier::class)->verify($token, 'test-project'));
        $this->assertNull(app(FirebaseIdTokenVerifier::class)->verify('invalid', 'test-project'));
        Http::assertNothingSent();
    }

    public function test_rs256_token_signed_by_a_different_key_is_rejected(): void
    {
        $trusted = openssl_pkey_new(['private_key_bits' => 2048]);
        $untrusted = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($untrusted, $private);
        Cache::put('auth:firebase:certificates', ['unit-key' => openssl_pkey_get_details($trusted)['key']], 300);
        $token = JWT::encode([
            'iss' => 'https://securetoken.google.com/test-project', 'aud' => 'test-project', 'sub' => 'subject',
            'iat' => time() - 1, 'auth_time' => time() - 1, 'exp' => time() + 300,
            'email' => 'test@example.com', 'email_verified' => true,
        ], $private, 'RS256', 'unit-key');
        $this->assertNull(app(FirebaseIdTokenVerifier::class)->verify($token, 'test-project'));
        Http::assertNothingSent();
    }
}
