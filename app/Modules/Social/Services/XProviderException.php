<?php

namespace App\Modules\Social\Services;

use Illuminate\Http\Client\Response;

class XProviderException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly ?int $retryAfter = null,
        public readonly string $outcome = 'definite',
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, bool $creating = false): self
    {
        $status = $response->status();
        if ($status === 401) {
            return new self('Reconnect your X account.', 'reconnect');
        }
        if ($status === 403) {
            // Never expose provider detail, echoed content, or credentials.
            $credit = preg_match('/credit|balance|payment/i', $response->body()) === 1;

            return new self($credit ? 'X API credits are unavailable. Contact your administrator.' : 'X denied this action. Contact your administrator to check permissions and account access.', $credit ? 'credit' : 'permission');
        }
        if ($status === 429) {
            $retry = $response->header('Retry-After');
            $delay = is_numeric($retry) ? (int) $retry : (strtotime((string) $retry) ?: time()) - time();
            $reset = $response->header('x-rate-limit-reset');
            if (is_numeric($reset)) {
                $delay = max($delay, (int) $reset - time());
            }

            return new self('X rate limit reached. Retry later.', 'rate_limit', min(86400, max(1, $delay ?: 60)));
        }

        return new self('X request failed (HTTP '.$status.').', $creating && $status >= 500 ? 'unknown' : ($status >= 500 ? 'transient' : 'provider'), $status >= 500 ? 60 : null, $creating && $status >= 500 ? 'unknown' : 'definite');
    }
}
