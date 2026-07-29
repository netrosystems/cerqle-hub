<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PROSMS HTTP API.
 *
 * This installation's verified ProSMS contract uses HTTPS GET requests with
 * credentials and message fields in the query string. Never configure an HTTP
 * base URL: TLS is required because the provider authenticates every request.
 */
class AlarisSmsDriver implements SmsDriverInterface
{
    public const LONG_MESSAGE_MODES = ['cut', 'split', 'split_sar', 'single_id_split', 'payload'];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $senderId,
        private readonly string $serviceType = '',
        private readonly string $longMessageMode = '',
    ) {}

    public function send(string $to, string $body, array $opts = []): SmsSendResult
    {
        $payload = array_filter([
            'ani' => $opts['from'] ?? $this->senderId,
            // PROSMS documents E.164 digits. Cerqle stores numbers with a
            // leading plus, so normalise without changing the contact record.
            'dnis' => ltrim($to, '+'),
            'message' => $body,
            'serviceType' => $this->serviceType ?: null,
            'longMessageMode' => $this->normalizedLongMessageMode(),
        ], static fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->client()
                ->timeout(20)
                ->get($this->endpoint(), $this->query('submit', $payload));
        } catch (ConnectionException $e) {
            return new SmsSendResult(
                false,
                '',
                'PROSMS connection failed: '.$this->redact($e->getMessage()),
                null,
                true,
                false,
                true,
            );
        }

        $messageId = $response->successful() ? $this->messageId($response->json()) : null;

        return $messageId
            ? new SmsSendResult(true, $messageId)
            : new SmsSendResult(
                false,
                '',
                'PROSMS error: '.$this->errorMessage($response),
                $response->status(),
                $response->status() === 429 || $response->serverError(),
                $response->status() === 401,
                false,
                $this->retryAfterSeconds($response->header('Retry-After')),
            );
    }

    public function status(string $providerId): SmsStatus
    {
        try {
            $response = $this->client()
                ->timeout(15)
                ->get($this->endpoint(), $this->query('query', ['messageId' => $providerId]));
        } catch (ConnectionException $e) {
            return new SmsStatus($providerId, 'sent', 'PROSMS connection failed: '.$this->redact($e->getMessage()));
        }

        if (! $response->successful()) {
            return new SmsStatus($providerId, 'sent', $this->errorMessage($response));
        }

        $payload = $this->firstResult($response->json());
        $raw = strtolower((string) ($payload['status'] ?? $payload['delivery_status'] ?? ''));

        $status = match (true) {
            str_contains($raw, 'delivrd') || str_contains($raw, 'delivered') => 'delivered',
            str_contains($raw, 'undeliv') || str_contains($raw, 'failed') || str_contains($raw, 'reject') || str_contains($raw, 'expired') => 'failed',
            str_contains($raw, 'sent') || str_contains($raw, 'accept') || str_contains($raw, 'submit') => 'sent',
            default => 'queued',
        };

        return new SmsStatus($providerId, $status, $payload['error_code'] ?? null);
    }

    /**
     * Authenticate and query an impossible message ID. The guide documents an
     * UNKNOWN 200 response for IDs that do not exist, making this a non-billable
     * connectivity test.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(): array
    {
        $testId = 'cerqle-healthcheck-'.Str::uuid();

        try {
            $response = $this->client()
                ->timeout(15)
                ->get($this->endpoint(), $this->query('query', ['messageId' => $testId]));
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'Connection failed: '.$this->redact($e->getMessage())];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'message' => "PROSMS returned HTTP {$response->status()}: ".$this->errorMessage($response),
            ];
        }

        $payload = $this->firstResult($response->json());
        $status = strtoupper((string) ($payload['status'] ?? ''));

        return [
            'ok' => true,
            'message' => $status === 'UNKNOWN'
                ? 'PROSMS authentication and API connectivity are working.'
                : 'PROSMS API connection succeeded'.($status !== '' ? " (status: {$status})." : '.'),
        ];
    }

    private function client()
    {
        return Http::acceptJson()
            ->connectTimeout(8);
    }

    private function endpoint(): string
    {
        return rtrim(trim($this->baseUrl), '?&');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function query(string $command, array $payload = []): array
    {
        return array_merge([
            'serviceType' => $this->serviceType,
            'longMessageMode' => $this->normalizedLongMessageMode(),
            'username' => $this->username,
            'password' => $this->password,
        ], $payload, ['command' => $command]);
    }

    private function normalizedLongMessageMode(): string
    {
        $mode = strtolower(trim($this->longMessageMode));

        return in_array($mode, self::LONG_MESSAGE_MODES, true) ? $mode : 'split';
    }

    private function redact(string $message): string
    {
        return str_replace(
            array_filter([$this->username, $this->password]),
            '[redacted]',
            $message
        );
    }

    private function messageId(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['message_id', 'messageId', 'id'] as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        // PROSMS may return an array of result objects. Campaign jobs submit one
        // recipient at a time, so the first returned message ID is the one to track.
        foreach ($payload as $item) {
            $messageId = $this->messageId($item);
            if ($messageId) {
                return $messageId;
            }
        }

        return null;
    }

    /**
     * PROSMS wraps submit and query results in a JSON array, including when
     * only one message ID was requested.
     *
     * @return array<string, mixed>
     */
    private function firstResult(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return is_array($payload[0] ?? null) ? $payload[0] : [];
        }

        return $payload;
    }

    private function errorMessage($response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $json = $this->firstResult($json);
        }

        return (string) ($json['error'] ?? $json['message'] ?? $json['description'] ?? $response->body() ?: 'Request was rejected');
    }

    private function retryAfterSeconds(?string $value): ?int
    {
        if (! filled($value)) {
            return null;
        }
        if (ctype_digit($value)) {
            return max(1, (int) $value);
        }
        $timestamp = strtotime($value);

        return $timestamp ? max(1, $timestamp - time()) : null;
    }
}
