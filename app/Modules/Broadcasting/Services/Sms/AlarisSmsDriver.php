<?php

namespace App\Modules\Broadcasting\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * PROSMS HTTP API.
 *
 * The platform accepts credentials with each request. We use HTTPS and HTTP
 * Basic authentication rather than placing credentials in the query string.
 */
class AlarisSmsDriver implements SmsDriverInterface
{
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
            'longMessageMode' => $this->longMessageMode ?: null,
        ], static fn ($value) => $value !== null && $value !== '');

        $response = Http::acceptJson()
            ->withBasicAuth($this->username, $this->password)
            ->timeout(15)
            ->post($this->endpoint('submit'), $payload);

        $messageId = $response->successful() ? $this->messageId($response->json()) : null;

        return $messageId
            ? new SmsSendResult(true, $messageId)
            : new SmsSendResult(false, '', 'PROSMS error: '.$this->errorMessage($response));
    }

    public function status(string $providerId): SmsStatus
    {
        $response = Http::acceptJson()
            ->withBasicAuth($this->username, $this->password)
            ->timeout(10)
            // Laravel replaces an existing query string when the second argument
            // is supplied to get(), so append the provider message ID ourselves.
            ->get($this->endpoint('query').'&messageId='.rawurlencode($providerId));

        if (! $response->successful()) {
            return new SmsStatus($providerId, 'sent', $this->errorMessage($response));
        }

        $payload = $response->json();
        $raw = strtolower((string) ($payload['status'] ?? $payload['delivery_status'] ?? ''));

        $status = match (true) {
            str_contains($raw, 'delivrd') || str_contains($raw, 'delivered') => 'delivered',
            str_contains($raw, 'undeliv') || str_contains($raw, 'failed') || str_contains($raw, 'reject') || str_contains($raw, 'expired') => 'failed',
            str_contains($raw, 'sent') || str_contains($raw, 'accept') || str_contains($raw, 'submit') => 'sent',
            default => 'queued',
        };

        return new SmsStatus($providerId, $status, $payload['error_code'] ?? null);
    }

    private function endpoint(string $command): string
    {
        $base = rtrim(trim($this->baseUrl), '?&');

        return $base.(str_contains($base, '?') ? '&' : '?').'command='.$command;
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

    private function errorMessage($response): string
    {
        $json = $response->json();

        return (string) ($json['error'] ?? $json['message'] ?? $json['description'] ?? $response->body() ?: 'Request was rejected');
    }
}
