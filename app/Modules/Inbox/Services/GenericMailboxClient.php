<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class GenericMailboxClient
{
    private const TYPE_TEXT = 0;

    private const ENCODING_7BIT = 0;

    private const ENCODING_BASE64 = 3;

    private const ENCODING_QUOTED_PRINTABLE = 4;

    public function verify(ChannelAccount $account): bool
    {
        $this->open($account, true);

        $transport = $this->mailer($account)->getSymfonyTransport();

        try {
            // Building an on-demand mailer does not open a connection. Starting
            // the transport forces the SMTP handshake and AUTH exchange without
            // sending a test message to a real recipient.
            $transport->start();
        } catch (Throwable $e) {
            throw new RuntimeException('SMTP connection failed: '.$e->getMessage(), 0, $e);
        } finally {
            $transport->stop();
        }

        return true;
    }

    public function messages(ChannelAccount $account): array
    {
        $imap = $this->open($account);
        $meta = $account->meta_json ?? [];
        $since = isset($meta['last_synced_at']) ? Carbon::parse($meta['last_synced_at'])->subDay() : now()->subDays(7);
        $uids = imap_search($imap, 'SINCE "'.$since->format('d-M-Y').'"', SE_UID) ?: [];
        $messages = [];

        foreach (array_slice($uids, -200) as $uid) {
            $overview = imap_fetch_overview($imap, (string) $uid, FT_UID)[0] ?? null;
            if (! $overview) {
                continue;
            }
            $header = imap_headerinfo($imap, imap_msgno($imap, $uid));
            $from = $header->from[0] ?? null;
            $body = $this->messageBody($imap, (int) $uid);
            $messages[] = [
                'id' => 'imap:'.$account->id.':'.$uid,
                'internetMessageId' => trim((string) ($overview->message_id ?? 'imap-'.$account->id.'-'.$uid), '<>'),
                'conversationId' => trim((string) ($overview->references ?? $overview->in_reply_to ?? $overview->message_id ?? $uid), '<>'),
                'subject' => isset($overview->subject) ? $this->decodeHeader($overview->subject) : '(no subject)',
                'from' => ['emailAddress' => [
                    'address' => $from ? ($from->mailbox.'@'.$from->host) : '',
                    'name' => $from?->personal ? $this->decodeHeader($from->personal) : '',
                ]],
                'receivedDateTime' => isset($overview->date) ? date(DATE_ATOM, strtotime($overview->date)) : now()->toIso8601String(),
                'bodyPreview' => mb_substr($body, 0, 500),
                'body' => ['content' => $body],
                'isRead' => ! empty($overview->seen),
            ];
        }
        imap_close($imap);
        $account->update(['meta_json' => array_merge($meta, [
            'last_synced_at' => now()->toIso8601String(),
            'last_sync_error' => null,
        ])]);

        return $messages;
    }

    public function send(ChannelAccount $account, string $to, string $subject, string $body, ?string $inReplyTo = null): string
    {
        $credentials = $account->credentials ?? [];
        $fromAddress = (string) ($account->meta_json['email'] ?? $credentials['username']);
        $mailer = $this->mailer($account);
        $sent = $mailer->html(nl2br(e($body)), function ($message) use ($account, $fromAddress, $to, $subject, $inReplyTo): void {
            $message->to($to)->subject($subject)->from($fromAddress, $account->display_name ?: null);
            if ($inReplyTo) {
                $message->getHeaders()->addTextHeader('In-Reply-To', '<'.trim($inReplyTo, '<>').'>');
                $message->getHeaders()->addTextHeader('References', '<'.trim($inReplyTo, '<>').'>');
            }
        });

        return $sent?->getMessageId() ?: 'smtp:'.bin2hex(random_bytes(12));
    }

    private function messageBody(mixed $imap, int $uid): string
    {
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        if (! $structure) {
            return '';
        }

        $part = $this->preferredTextPart($structure);
        if (! $part) {
            return '';
        }

        $raw = $part['section'] === null
            ? (string) imap_body($imap, $uid, FT_UID | FT_PEEK)
            : (string) imap_fetchbody($imap, $uid, $part['section'], FT_UID | FT_PEEK);

        return $this->decodeBody($raw, $part['encoding'], $part['charset'], $part['html']);
    }

    /**
     * Return the best readable MIME part without ever selecting an attachment.
     * Plain text is preferred because the inbox stores safe text, while HTML is
     * retained as a fallback and converted to readable text below.
     */
    private function preferredTextPart(object $structure, ?string $section = null): ?array
    {
        $parts = $structure->parts ?? [];
        if ($parts === []) {
            if ((int) ($structure->type ?? -1) !== self::TYPE_TEXT || $this->isAttachment($structure)) {
                return null;
            }

            $subtype = strtoupper((string) ($structure->subtype ?? 'PLAIN'));
            if (! in_array($subtype, ['PLAIN', 'HTML'], true)) {
                return null;
            }

            return [
                'section' => $section,
                'encoding' => (int) ($structure->encoding ?? self::ENCODING_7BIT),
                'charset' => $this->partParameter($structure, 'charset') ?: 'UTF-8',
                'html' => $subtype === 'HTML',
            ];
        }

        $plain = null;
        $html = null;
        foreach ($parts as $index => $child) {
            $childSection = $section === null ? (string) ($index + 1) : $section.'.'.($index + 1);
            $candidate = $this->preferredTextPart($child, $childSection);
            if (! $candidate) {
                continue;
            }
            if (! $candidate['html'] && $plain === null) {
                $plain = $candidate;
            }
            if ($candidate['html'] && $html === null) {
                $html = $candidate;
            }
        }

        return $plain ?: $html;
    }

    private function decodeBody(string $raw, int $encoding, string $charset, bool $html): string
    {
        $decoded = match ($encoding) {
            self::ENCODING_BASE64 => base64_decode($raw, true) ?: '',
            self::ENCODING_QUOTED_PRINTABLE => quoted_printable_decode($raw),
            default => $raw,
        };

        $charset = strtoupper(trim($charset, " \t\n\r\0\x0B\"'"));
        if ($decoded !== '' && $charset !== '' && ! in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
            $converted = @mb_convert_encoding($decoded, 'UTF-8', $charset);
            if (is_string($converted)) {
                $decoded = $converted;
            }
        }

        if ($html) {
            $decoded = preg_replace('/<\s*(br\s*\/?|\/p|\/div|\/li|\/tr|\/h[1-6])\s*>/i', "\n", $decoded) ?? $decoded;
            $decoded = html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $decoded = str_replace(["\r\n", "\r"], "\n", $decoded);
        $decoded = preg_replace("/\n{3,}/", "\n\n", $decoded) ?? $decoded;

        return trim($decoded);
    }

    private function isAttachment(object $part): bool
    {
        if (strtoupper((string) ($part->disposition ?? '')) === 'ATTACHMENT') {
            return true;
        }

        return $this->partParameter($part, 'filename') !== null
            || $this->partParameter($part, 'name') !== null;
    }

    private function partParameter(object $part, string $name): ?string
    {
        foreach (array_merge((array) ($part->parameters ?? []), (array) ($part->dparameters ?? [])) as $parameter) {
            if (strcasecmp((string) ($parameter->attribute ?? ''), $name) === 0) {
                return (string) ($parameter->value ?? '');
            }
        }

        return null;
    }

    private function decodeHeader(string $value): string
    {
        $decoded = '';
        foreach (imap_mime_header_decode($value) ?: [] as $part) {
            $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
            $text = (string) ($part->text ?? '');
            if (! in_array($charset, ['DEFAULT', 'UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
                $text = @mb_convert_encoding($text, 'UTF-8', $charset) ?: $text;
            }
            $decoded .= $text;
        }

        return trim($decoded) ?: trim($value);
    }

    private function open(ChannelAccount $account, bool $close = false): mixed
    {
        if (! function_exists('imap_open')) {
            throw new RuntimeException('The PHP IMAP extension is required for generic mailbox sync.');
        }
        $c = $account->credentials ?? [];
        $flags = match ($c['imap_encryption'] ?? 'ssl') {
            'tls' => '/tls', 'none' => '/notls', default => '/ssl',
        };
        if (! ($c['verify_tls'] ?? true)) {
            $flags .= '/novalidate-cert';
        }
        $mailbox = sprintf('{%s:%d/imap%s}INBOX', $c['imap_host'], $c['imap_port'], $flags);
        $imap = @imap_open($mailbox, $c['username'], $c['password'], 0, 1);
        if (! $imap) {
            throw new RuntimeException('IMAP connection failed: '.(imap_last_error() ?: 'unknown error'));
        }
        if ($close) {
            imap_close($imap);
        }

        return $imap;
    }

    private function mailer(ChannelAccount $account): mixed
    {
        $c = $account->credentials ?? [];
        $encryption = $c['smtp_encryption'] ?? 'tls';

        return Mail::build([
            'transport' => 'smtp',
            // Laravel 12/Symfony Mailer uses the scheme, not the legacy
            // "encryption" key, to select implicit TLS on port 465.
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $c['smtp_host'],
            'port' => (int) $c['smtp_port'],
            'username' => $c['username'],
            'password' => $c['password'],
            'timeout' => 20,
            'auto_tls' => $encryption !== 'none',
            'require_tls' => $encryption === 'tls',
            'verify_peer' => (bool) ($c['verify_tls'] ?? true),
        ]);
    }
}
