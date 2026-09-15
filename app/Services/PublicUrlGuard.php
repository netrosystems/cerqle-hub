<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PublicUrlGuard
{
    /**
     * Validate every resolved address, returning a pinned public destination.
     *
     * @return array{host: string, port: int, ip: string}
     */
    public function destination(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            $this->reject();
        }
        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        // parse_url rejects ports above 65535; reject the remaining invalid zero port.
        if ($port < 1) {
            $this->reject();
        }
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            $this->reject();
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                $this->reject();
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        foreach ($records ?: [] as $record) {
            if (isset($record['ip']) || isset($record['ipv6'])) {
                $addresses[] = $record['ip'] ?? $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    public function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            $binary = inet_pton($ip);

            // Global unicast only, excluding documentation and transition ranges.
            // Compare binary prefixes so expanded/uppercase spellings cannot bypass this.
            return $binary !== false && (ord($binary[0]) & 0xE0) === 0x20
                && substr($binary, 0, 4) !== "\x20\x01\x0d\xb8"
                && substr($binary, 0, 4) !== "\x20\x01\x00\x00"
                && substr($binary, 0, 2) !== "\x20\x02";
        }
        $octets = array_map('intval', explode('.', $ip));

        return ! ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127)
            && ! ($octets[0] === 192 && $octets[1] === 0)
            && ! ($octets[0] === 198 && in_array($octets[1], [18, 19], true))
            && $octets[0] < 224;
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['url' => 'Use a publicly reachable HTTP/HTTPS URL. Private network destinations are not allowed.']);
    }
}
