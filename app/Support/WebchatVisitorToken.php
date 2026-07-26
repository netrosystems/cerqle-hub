<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

/**
 * Opaque, tamper-proof session token issued to an anonymous website chat
 * visitor. Once a visitor sends a message it binds that browser session to
 * exactly ONE conversation + widget, so a visitor can only read/write their own
 * thread. Before the first message, the conversation id is null so merely
 * opening the widget does not create empty inbox conversations.
 */
class WebchatVisitorToken
{
    /** Issue a token bound to a widget + visitor id, optionally with a conversation. */
    public static function issue(?int $conversationId, string $widgetKey, string $visitorId, int $ttlHours = 720): string
    {
        return Crypt::encryptString(json_encode([
            'c' => $conversationId,
            'w' => $widgetKey,
            'v' => $visitorId,
            'e' => now()->addHours($ttlHours)->getTimestamp(),
        ]));
    }

    /**
     * Verify a token against the widget it must belong to. Returns the decoded
     * payload (['c'=>convId|null,'w'=>key,'v'=>visitorId,'e'=>exp]) or null if the
     * token is invalid, tampered, for another widget, or expired.
     *
     * @return array{c:int|null,w:string,v:string,e:int}|null
     */
    public static function verify(string $token, string $widgetKey): ?array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($data)) {
            return null;
        }
        if (($data['w'] ?? null) !== $widgetKey) {
            return null;
        }
        if ((int) ($data['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $data;
    }
}
