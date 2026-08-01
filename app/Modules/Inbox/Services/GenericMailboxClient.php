<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\ChannelAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class GenericMailboxClient
{
    public function verify(ChannelAccount $account): bool
    {
        $this->open($account, true);
        $this->mailer($account);

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
            $messages[] = [
                'id' => 'imap:'.$account->id.':'.$uid,
                'internetMessageId' => trim((string) ($overview->message_id ?? 'imap-'.$account->id.'-'.$uid), '<>'),
                'conversationId' => trim((string) ($overview->references ?? $overview->in_reply_to ?? $overview->message_id ?? $uid), '<>'),
                'subject' => isset($overview->subject) ? imap_utf8($overview->subject) : '(no subject)',
                'from' => ['emailAddress' => [
                    'address' => $from ? ($from->mailbox.'@'.$from->host) : '',
                    'name' => $from?->personal ? imap_utf8($from->personal) : '',
                ]],
                'receivedDateTime' => isset($overview->date) ? date(DATE_ATOM, strtotime($overview->date)) : now()->toIso8601String(),
                'bodyPreview' => trim(strip_tags(quoted_printable_decode((string) imap_body($imap, $uid, FT_UID | FT_PEEK)))),
                'body' => ['content' => quoted_printable_decode((string) imap_body($imap, $uid, FT_UID | FT_PEEK))],
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
        $mailer = $this->mailer($account);
        $sent = $mailer->html(nl2br(e($body)), function ($message) use ($credentials, $to, $subject, $inReplyTo): void {
            $message->to($to)->subject($subject)->from($credentials['username'], $credentials['from_name'] ?? null);
            if ($inReplyTo) {
                $message->getHeaders()->addTextHeader('In-Reply-To', '<'.trim($inReplyTo, '<>').'>');
                $message->getHeaders()->addTextHeader('References', '<'.trim($inReplyTo, '<>').'>');
            }
        });

        return $sent?->getMessageId() ?: 'smtp:'.bin2hex(random_bytes(12));
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

        return Mail::build([
            'transport' => 'smtp',
            'host' => $c['smtp_host'],
            'port' => (int) $c['smtp_port'],
            'encryption' => ($c['smtp_encryption'] ?? 'tls') === 'none' ? null : $c['smtp_encryption'],
            'username' => $c['username'],
            'password' => $c['password'],
            'timeout' => 20,
            'verify_peer' => (bool) ($c['verify_tls'] ?? true),
        ]);
    }
}
