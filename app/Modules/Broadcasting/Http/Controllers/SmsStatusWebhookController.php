<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles inbound delivery-status webhooks from Twilio, Nexmo (Vonage), and MessageBird.
 *
 * All three share the same controller; the route {provider} segment determines which
 * signature verification and field parsing logic to use.
 */
class SmsStatusWebhookController extends Controller
{
    public function __construct(private WebhookIdempotencyService $idempotency) {}

    public function handle(Request $request, string $provider): Response
    {
        match ($provider) {
            'twilio' => $this->verifyTwilio($request),
            'nexmo' => $this->verifyNexmo($request),
            'messagebird' => $this->verifyMessageBird($request),
            'smsbd' => $this->verifySmsBd($request),
            'reve' => $this->verifyReve($request),
            'alaris' => $this->verifyGenericToken($request, 'alaris'),
            'bulksmsbd' => $this->verifyGenericToken($request, 'bulksmsbd'),
            'sms_dot_bd' => null, // sms.net.bd does not sign DLR callbacks
            'mimsms' => $this->verifyGenericToken($request, 'mimsms'),
            'fast2sms' => $this->verifyGenericToken($request, 'fast2sms'),
            default => abort(404),
        };

        [$msgId, $status] = match ($provider) {
            'twilio' => [$request->input('MessageSid'), $this->mapTwilioStatus($request->input('MessageStatus'))],
            'nexmo' => [$request->input('messageId'), $this->mapNexmoStatus($request->input('status'))],
            'messagebird' => [$request->input('id'), $this->mapMessageBirdStatus($request->input('status'))],
            'smsbd' => [$request->input('Message_ID') ?? $request->input('msgid'), $this->mapSmsBdStatus($request->input('Delivery_Status') ?? $request->input('status'))],
            'reve' => [$request->input('message_id'), $this->mapReveStatus($request->input('status') ?? $request->input('delivery_status'))],
            'alaris' => [$request->input('dlvrMsgId') ?? $request->input('messageId') ?? $request->input('message_id'), $this->mapSmsBdStatus($request->input('dlvrMsgStat') ?? $request->input('status') ?? $request->input('delivery_status'))],
            'bulksmsbd' => [$request->input('message_id'), $this->mapSmsBdStatus($request->input('status') ?? $request->input('delivery_status'))],
            'sms_dot_bd' => [$request->input('batch_id') ?? $request->input('message_id'), $this->mapSmsBdStatus($request->input('status'))],
            'mimsms' => [$request->input('message_id'), $this->mapSmsBdStatus($request->input('status') ?? $request->input('delivery_status'))],
            'fast2sms' => [$request->input('request_id'), $this->mapFast2SmsStatus($request->input('status'))],
            default => [null, null],
        };

        if (! $msgId || ! $status) {
            return new Response('OK', 200);
        }

        // Idempotency
        if (! $this->idempotency->isNewEvent("sms_status_{$provider}", "{$msgId}_{$status}")) {
            return new Response('OK', 200);
        }

        $statusMap = [
            'queued' => 'queued',
            'sent' => 'sent',
            'delivered' => 'delivered',
            // SMS does not support a recipient read receipt. A provider that
            // reports this value is treated as a delivery confirmation.
            'read' => 'delivered',
            'failed' => 'failed',
        ];
        $mapped = $statusMap[$status] ?? null;

        if ($mapped) {
            $recipient = CampaignRecipient::where('provider_message_id', $msgId)->first();
            if ($recipient) {
                // Status priority — never downgrade.
                $priority = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'failed' => 3];
                $current = $priority[$recipient->status] ?? 0;
                $next = $priority[$mapped] ?? 0;

                if ($next >= $current) {
                    $now = now();
                    $patch = ['status' => $mapped];

                    if ($mapped === 'sent' && ! $recipient->sent_at) {
                        $patch['sent_at'] = $now;
                    }
                    if ($mapped === 'delivered') {
                        if (! $recipient->sent_at) {
                            $patch['sent_at'] = $now;
                        }
                        if (! $recipient->delivered_at) {
                            $patch['delivered_at'] = $now;
                        }
                    }
                    if ($mapped === 'failed') {
                        $providerError = $request->input('ErrorMessage')
                            ?? $request->input('error_message')
                            ?? $request->input('errorCode')
                            ?? $request->input('Reason')
                            ?? 'failed';
                        $patch['failed_reason'] = substr((string) $providerError, 0, 512);
                    }

                    $recipient->update($patch);
                }
            }
            Log::info("SMS status update [{$provider}]", ['msg_id' => $msgId, 'status' => $mapped]);
        }

        return new Response('OK', 200);
    }

    private function verifyNexmo(Request $request): void
    {
        $secret = config('services.nexmo.api_secret') ?? config('services.vonage.api_secret') ?? null;
        if (! $secret) {
            abort_if(app()->environment('production'), 503, 'Vonage webhook secret is not configured');

            return;
        }

        // Vonage DLR: signature is provided in "sig" param (HMAC-MD5 of sorted params)
        $sig = $request->input('sig', '');
        if (! $sig) {
            // No signature present — allow in non-production
            if (app()->environment('production')) {
                abort(401, 'Missing Vonage signature');
            }

            return;
        }

        $params = $request->all();
        unset($params['sig']);
        ksort($params);
        $message = '&'.urldecode(http_build_query($params, '', '&'));
        $expected = md5($message.$secret);

        abort_unless(hash_equals($expected, strtolower($sig)), 401, 'Invalid Vonage signature');
    }

    private function verifyMessageBird(Request $request): void
    {
        $secret = config('services.messagebird.signing_key') ?? null;
        if (! $secret) {
            abort_if(app()->environment('production'), 503, 'MessageBird webhook signing key is not configured');

            return;
        }

        $signature = $request->header('MessageBird-Signature', '');
        if (! $signature) {
            if (app()->environment('production')) {
                abort(401, 'Missing MessageBird signature');
            }

            return;
        }

        // MessageBird: ts.queryString.sha256BodyHash signed with HMAC-SHA256
        $ts = $request->header('MessageBird-Request-Timestamp', '');
        $queryString = $request->server('QUERY_STRING', '');
        $bodyHash = hash('sha256', $request->getContent());
        $payload = "{$ts}\n{$queryString}\n{$bodyHash}";
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));

        abort_unless(hash_equals($expected, $signature), 401, 'Invalid MessageBird signature');
    }

    private function verifyTwilio(Request $request): void
    {
        // Twilio sends X-Twilio-Signature; verify if configured
        $authToken = config('services.twilio.auth_token') ?? null;
        if (! $authToken) {
            abort_if(app()->environment('production'), 503, 'Twilio webhook auth token is not configured');

            return;
        }

        $url = $request->fullUrl();
        $params = $request->all();
        ksort($params);
        $data = $url.implode('', array_map(fn ($k, $v) => $k.$v, array_keys($params), $params));
        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));
        $received = $request->header('X-Twilio-Signature', '');

        abort_unless(hash_equals($expected, $received), 401, 'Invalid Twilio signature');
    }

    private function mapTwilioStatus(string $s): string
    {
        return match ($s) {
            'sent', 'sending' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed', 'undelivered' => 'failed',
            default => 'queued',
        };
    }

    private function mapNexmoStatus(string $s): string
    {
        return match ($s) {
            'delivered' => 'delivered',
            'failed', 'expired', 'rejected', 'unknown' => 'failed',
            // Vonage uses buffered while the message is waiting for delivery;
            // it is not a terminal failure and must not downgrade a recipient.
            'buffered' => 'queued',
            'accepted' => 'sent',
            default => 'queued',
        };
    }

    private function mapMessageBirdStatus(string $s): string
    {
        return match ($s) {
            'delivered' => 'delivered',
            'sent' => 'sent',
            'failed', 'delivery_failed' => 'failed',
            default => 'queued',
        };
    }

    private function verifySmsBd(Request $request): void
    {
        $secret = config('services.smsbd.webhook_secret');
        if (! $secret) {
            abort_if(app()->environment('production'), 503, 'SMSBD webhook secret is not configured');

            return;
        }
        $token = $request->input('token') ?: $request->header('X-Smsbd-Token', '');
        abort_unless(hash_equals($secret, $token), 401, 'Invalid SMSBD webhook token');
    }

    private function verifyGenericToken(Request $request, string $key): void
    {
        $secret = config("services.{$key}.webhook_secret");
        if (! $secret) {
            abort_if(app()->environment('production'), 503, ucfirst($key).' webhook secret is not configured');

            return;
        }
        $token = $request->input('token') ?: $request->header('X-Webhook-Token', '');
        abort_unless(hash_equals($secret, $token), 401, 'Invalid webhook token');
    }

    private function verifyReve(Request $request): void
    {
        $secret = config('services.reve.webhook_secret');
        if (! $secret) {
            abort_if(app()->environment('production'), 503, 'REVE webhook secret is not configured');

            return;
        }
        $token = $request->header('X-Reve-Token') ?: $request->input('token', '');
        abort_unless(hash_equals($secret, $token), 401, 'Invalid REVE webhook token');
    }

    private function mapSmsBdStatus(string $s): string
    {
        $s = strtolower($s);

        return match (true) {
            str_contains($s, 'delivrd') || str_contains($s, 'delivered') => 'delivered',
            str_contains($s, 'failed') || str_contains($s, 'undeliv') || str_contains($s, 'rejectd') => 'failed',
            str_contains($s, 'sent') || str_contains($s, 'accept') || str_contains($s, 'submit') => 'sent',
            default => 'queued',
        };
    }

    private function mapReveStatus(string $s): string
    {
        $s = strtolower($s);

        return match (true) {
            str_contains($s, 'delivrd') || str_contains($s, 'delivered') => 'delivered',
            str_contains($s, 'failed') || str_contains($s, 'undeliv') || str_contains($s, 'reject') => 'failed',
            str_contains($s, 'sent') || str_contains($s, 'accept') || str_contains($s, 'submit') => 'sent',
            default => 'queued',
        };
    }

    private function mapFast2SmsStatus(?string $s): string
    {
        $s = strtolower((string) $s);

        return match (true) {
            str_contains($s, 'delivrd') || str_contains($s, 'delivered') => 'delivered',
            str_contains($s, 'undeliv') || str_contains($s, 'failed') || str_contains($s, 'rejectd') => 'failed',
            str_contains($s, 'sent') || str_contains($s, 'submit') || str_contains($s, 'accept') => 'sent',
            default => 'queued',
        };
    }
}
