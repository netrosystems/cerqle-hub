<?php

namespace App\Modules\Inbox\Services;

use App\Modules\Shared\Models\Message;

/**
 * Decides whether an inbound email is someone asking for help, or one of the
 * many things a shared mailbox receives that nobody should answer: newsletters,
 * receipts, alerts, bounces, no-reply notifications.
 *
 * Every signal here is free and deterministic — headers the sending system set
 * about itself, the shape of the sender address, and structural properties of
 * the body. No model is consulted, so the verdict costs nothing, never varies
 * between two identical messages, and can be explained to an operator in one
 * sentence.
 *
 * **When the signals do not decide, the mail is answered.** A real customer
 * left waiting is a worse outcome than an occasional reply to a notification,
 * so `uncertain` still replies and is flagged in the inbox rather than held
 * back silently.
 *
 * Deliberately not done here: judging the prose. Guessing "this reads like
 * marketing" from wording means a word list, which works in English and fails
 * in every other language a customer might write in.
 */
class EmailTriage
{
    /** A person asking something. Answer it. */
    public const INQUIRY = 'inquiry';

    /** Mixed signals. Answer it, but say so. */
    public const UNCERTAIN = 'uncertain';

    /** Newsletter, campaign or mailing list. */
    public const BULK = 'bulk';

    /** Machine-generated: receipts, alerts, no-reply notifications. */
    public const AUTOMATED = 'automated';

    /** A bounce, or our own address. Answering risks a loop. */
    public const LOOP = 'loop';

    /**
     * Local parts that mailers use to say "nobody reads this".
     *
     * This is a convention of the medium rather than of a language: these
     * literal strings are what systems worldwide put in front of the @,
     * whatever language the message itself is written in.
     */
    private const NO_REPLY_SENDERS = '/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply|bounce[sd]?|notification[s]?|alert[s]?|auto-?confirm)([+.-]|@)/i';

    /** Weaker sender hints: usually machine mail, but a person may sit behind them. */
    private const IMPERSONAL_SENDERS = '/^(info|news|newsletter|marketing|updates?|billing|invoices?|receipts?|statements?|system|mail|admin|noc|team)([+.-]|@)/i';

    /**
     * @return array{category:string, reply:bool, confident:bool, signals:list<string>, summary:string}
     */
    public function classify(Message $message): array
    {
        $payload = is_array($message->payload) ? $message->payload : [];
        $headers = array_change_key_case((array) ($payload['mail_headers'] ?? []), CASE_LOWER);
        $from = strtolower(trim((string) ($payload['from_address'] ?? $message->conversation?->contact?->email ?? '')));
        $self = strtolower(trim((string) ($message->conversation?->channelAccount?->meta_json['email'] ?? '')));

        // ── Loops first: answering these can bounce back and forth for ever ──
        if ($from !== '' && $from === $self) {
            return $this->verdict(self::LOOP, false, true, ['own_address'], 'From this mailbox itself.');
        }
        if (str_contains(strtolower((string) ($headers['content-type'] ?? '')), 'report-type=delivery-status')) {
            return $this->verdict(self::LOOP, false, true, ['delivery_status_report'], 'A delivery failure report, not a message from a person.');
        }
        if (preg_match(self::NO_REPLY_SENDERS, $from)) {
            return $this->verdict(self::AUTOMATED, false, true, ['no_reply_sender'], 'Sent from an address that does not accept replies.');
        }

        // ── Headers the sender set about itself ──────────────────────────────
        $autoSubmitted = strtolower(trim((string) ($headers['auto-submitted'] ?? '')));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return $this->verdict(self::AUTOMATED, false, true, ['auto_submitted'], 'The sending system marked this as automatic.');
        }
        if (strtolower((string) ($headers['x-auto-response-suppress'] ?? '')) === 'all') {
            return $this->verdict(self::AUTOMATED, false, true, ['auto_response_suppressed'], 'The sender asked for no automatic replies.');
        }
        if (isset($headers['list-id']) || isset($headers['list-unsubscribe'])) {
            return $this->verdict(self::BULK, false, true, ['mailing_list_headers'], 'Sent to a mailing list, not to a person.');
        }
        if (in_array(strtolower((string) ($headers['precedence'] ?? '')), ['bulk', 'list', 'junk'], true)) {
            return $this->verdict(self::BULK, false, true, ['bulk_precedence'], 'Marked as bulk mail by the sender.');
        }

        // ── Nothing declared. Weigh what the message looks like ──────────────
        $signals = [];
        if ($from !== '' && preg_match(self::IMPERSONAL_SENDERS, $from)) {
            $signals[] = 'impersonal_sender';
        }
        $html = (string) ($payload['html_body'] ?? '');
        $body = (string) $message->body;
        if ($html !== '' && $this->hasUnsubscribeLink($html)) {
            $signals[] = 'unsubscribe_link';
        }
        if ($html !== '' && $this->hasTrackingPixel($html)) {
            $signals[] = 'tracking_pixel';
        }
        if ($html !== '' && $this->isLinkHeavy($html, $body)) {
            $signals[] = 'link_heavy';
        }

        // An unsubscribe link is the strongest of these: transactional mail
        // does not carry one, because there is nothing to unsubscribe from.
        $bulkish = in_array('unsubscribe_link', $signals, true)
            ? count($signals) >= 2
            : count($signals) >= 3;

        if ($bulkish) {
            return $this->verdict(self::BULK, false, false, $signals, 'Looks like a campaign or newsletter rather than a question.');
        }
        if ($signals !== []) {
            // The chosen bias: answer, and let the operator see the doubt.
            return $this->verdict(self::UNCERTAIN, true, false, $signals, 'Answered, though this may not be a customer question.');
        }

        return $this->verdict(self::INQUIRY, true, true, [], 'Looks like a message from a person.');
    }

    /** Whether an automatic reply should be attempted at all. */
    public function shouldReply(Message $message): bool
    {
        return $this->classify($message)['reply'];
    }

    private function hasUnsubscribeLink(string $html): bool
    {
        // Matched on the URL and on link text. A localised campaign still
        // points at an English-ish endpoint far more often than not, and the
        // header check above already caught the well-behaved senders.
        return (bool) preg_match('/(unsubscribe|opt[-_]?out|desinscri|abmelden|desuscribir|se-desabonner)/i', $html);
    }

    private function hasTrackingPixel(string $html): bool
    {
        return (bool) preg_match('/<img[^>]+(?:width\s*=\s*["\']?1["\']?[^>]*height\s*=\s*["\']?1|height\s*=\s*["\']?1["\']?[^>]*width\s*=\s*["\']?1)/i', $html);
    }

    /**
     * Campaigns are mostly links and pictures; a question is mostly words.
     * Counting rather than reading keeps this true in any language.
     */
    private function isLinkHeavy(string $html, string $text): bool
    {
        $links = preg_match_all('/<a\b[^>]*href=/i', $html);
        if ($links < 5) {
            return false;
        }
        $words = str_word_count(strip_tags($text)) ?: mb_strlen(trim($text)) / 6;

        return $words < $links * 25;
    }

    /**
     * @param  list<string>  $signals
     * @return array{category:string, reply:bool, confident:bool, signals:list<string>, summary:string}
     */
    private function verdict(string $category, bool $reply, bool $confident, array $signals, string $summary): array
    {
        return [
            'category' => $category,
            'reply' => $reply,
            'confident' => $confident,
            'signals' => $signals,
            'summary' => $summary,
        ];
    }
}
