<?php

namespace Tests\Feature\Security;

use App\Services\PublicHttpClient;
use App\Services\PublicUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicHttpBoundaryTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_private_or_invalid_urls_never_dispatch(string $url): void
    {
        Http::fake();
        try {
            app(PublicHttpClient::class)->send(Http::timeout(1), 'GET', $url);
            $this->fail('Unsafe URL was accepted.');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://127.0.0.1/private', 'http://10.0.0.1', 'http://169.254.169.254/latest/meta-data',
            'http://100.64.0.1', 'http://[::1]', 'http://[::ffff:127.0.0.1]',
            'http://[fd00::1]', 'http://[2001:db8::1]', 'file:///etc/passwd',
            'http://[2001:0DB8:0000:0000:0000:0000:0000:0001]', 'http://[2001::1]', 'http://[2002:a00:1::1]',
            'https://user:pass@8.8.8.8', 'http://2130706433', 'http://127.1',
        ]);
    }

    public function test_mixed_public_and_private_dns_answers_are_rejected(): void
    {
        $guard = $this->partialMock(PublicUrlGuard::class);
        $guard->shouldReceive('resolve')->with('mixed.example')->andReturn(['8.8.8.8', '10.0.0.1']);
        Http::fake();
        $this->expectException(ValidationException::class);
        try {
            app(PublicHttpClient::class)->send(Http::timeout(1), 'GET', 'https://mixed.example');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_public_custom_port_preserves_legitimate_integrations(): void
    {
        Http::fake(['https://8.8.8.8:8443/*' => fn () => Http::response(['ok' => true])]);
        $response = app(PublicHttpClient::class)->send(Http::timeout(1), 'GET', 'https://8.8.8.8:8443/store');
        $this->assertTrue($response->json('ok'));
        Http::assertSentCount(1);
    }

    public function test_public_get_redirect_to_private_is_blocked_before_second_dispatch(): void
    {
        Http::fake(['https://8.8.8.8/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        try {
            app(PublicHttpClient::class)->send(Http::timeout(1), 'GET', 'https://8.8.8.8/start', followRedirects: true);
            $this->fail('Private redirect accepted.');
        } catch (ValidationException) {
            Http::assertSentCount(1);
        }
    }

    public function test_public_request_keeps_hostname_and_pins_connection_options(): void
    {
        $guard = $this->partialMock(PublicUrlGuard::class);
        $guard->shouldReceive('resolve')->with('public.example')->andReturn(['8.8.8.8']);
        Http::fake(['public.example/*' => function ($request, $options) {
            $this->assertSame(['public.example:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);

            return Http::response(['ok' => true]);
        }]);
        $response = app(PublicHttpClient::class)->send(Http::timeout(1), 'POST', 'https://public.example/hook', ['json' => ['test' => true]]);
        $this->assertTrue($response->json('ok'));
        Http::assertSentCount(1);
    }

    public function test_response_size_is_bounded(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response(str_repeat('x', 33))]);
        $this->expectException(\RuntimeException::class);
        app(PublicHttpClient::class)->send(Http::timeout(1), 'GET', 'https://8.8.8.8/large', maxBytes: 32);
    }

    public function test_authenticated_post_never_follows_a_redirect(): void
    {
        Http::fake(['https://8.8.8.8/*' => Http::response('', 307, ['Location' => 'https://1.1.1.1/hook'])]);
        $this->expectException(\RuntimeException::class);
        try {
            app(PublicHttpClient::class)->send(Http::withBasicAuth('test', 'test'), 'POST', 'https://8.8.8.8/hook', ['json' => ['test' => true]], followRedirects: true);
        } finally {
            Http::assertSentCount(1);
        }
    }
}
