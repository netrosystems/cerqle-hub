<?php

namespace App\Services;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Http\Message\ResponseInterface;

class PublicHttpClient
{
    public function __construct(private PublicUrlGuard $guard) {}

    /** @param array<string, mixed> $options */
    public function send(PendingRequest $request, string $method, string $url, array $options = [], bool $followRedirects = false, int $maxBytes = 5242880): Response
    {
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('Public URL fetching requires the cURL extension.');
        }
        for ($hop = 0; $hop <= 3; $hop++) {
            $destination = $this->guard->destination($url);
            $ip = str_contains($destination['ip'], ':') ? '['.$destination['ip'].']' : $destination['ip'];
            // Literal addresses already identify the checked destination; only DNS names need pinning.
            $pins = filter_var($destination['host'], FILTER_VALIDATE_IP) ? [] : [$destination['host'].':'.$destination['port'].':'.$ip];
            $response = (clone $request)->setHandler(new CurlHandler)->withoutRedirecting()->withOptions([
                'proxy' => '',
                'stream' => true,
                'verify' => true,
                'connect_timeout' => 10,
                'curl' => [CURLOPT_RESOLVE => $pins],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new \RuntimeException('Remote response exceeds the allowed size.');
                    }
                },
                'progress' => function ($total, $downloaded) use ($maxBytes): void {
                    if ($total > $maxBytes || $downloaded > $maxBytes) {
                        throw new \RuntimeException('Remote response exceeds the allowed size.');
                    }
                },
            ])->send(strtoupper($method), $url, $options);
            $stream = $response->toPsrResponse()->getBody();
            $body = '';
            try {
                while (! $stream->eof()) {
                    $body .= $stream->read(min(8192, $maxBytes + 1 - strlen($body)));
                    if (strlen($body) > $maxBytes) {
                        throw new \RuntimeException('Remote response exceeds the allowed size.');
                    }
                }
            } finally {
                $stream->close();
            }
            $response = new Response($response->toPsrResponse()->withBody(Utils::streamFor($body)));
            if (! $response->redirect()) {
                return $response;
            }
            if (! $followRedirects || strtoupper($method) !== 'GET' || $hop === 3) {
                throw new \RuntimeException('Remote redirects are not allowed for this request. Use the final public URL.');
            }
            $location = $response->header('Location');
            if (! $location) {
                throw new \RuntimeException('Remote redirect has no destination.');
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            // Redirect following is only enabled by the credential-free indexer.
            $options = [];
        }
        throw new \RuntimeException('Too many remote redirects.');
    }
}
