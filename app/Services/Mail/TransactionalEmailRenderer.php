<?php

namespace App\Services\Mail;

class TransactionalEmailRenderer
{
    public function render(string $subject, string $content): string
    {
        // A super admin may intentionally store a complete custom document.
        // Preserve it instead of producing invalid nested html/body elements.
        if (preg_match('/(?:<!doctype\s+html|<html\b)/i', $content) === 1) {
            return $content;
        }

        // Existing templates are intentionally simple HTML. Inline the important
        // presentation rules so Gmail, Outlook and restrictive clients retain a
        // clear hierarchy without external CSS, fonts or image dependencies.
        $content = preg_replace(
            '/<a\s+(?![^>]*\bstyle=)/i',
            '<a style="background:#7c3f91;border:1px solid #7c3f91;border-radius:6px;color:#ffffff;display:inline-block;font-size:14px;font-weight:600;line-height:20px;padding:10px 18px;text-decoration:none;" ',
            $content
        ) ?? $content;

        return view('mail.layouts.transactional', [
            'subject' => $subject,
            'content' => $content,
            'appName' => config('app.name', 'Cerqle'),
            'appUrl' => config('app.url'),
        ])->render();
    }

    public function toPlainText(string $html): string
    {
        $text = preg_replace_callback(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            static function (array $matches): string {
                $label = trim(strip_tags($matches[2]));

                return $label.' ('.$matches[1].')';
            },
            $html
        ) ?? $html;

        $text = preg_replace('/<br\s*\/?\s*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text)."\n";
    }
}
