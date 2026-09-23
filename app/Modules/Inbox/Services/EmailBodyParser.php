<?php

namespace App\Modules\Inbox\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns a raw mail body into the two forms the app needs: readable text for
 * storage, and display-safe HTML for the reader.
 *
 * `strip_tags()` was doing this job and cannot. It removes the tags but keeps
 * the text *inside* them, so `<style>body{color:red}</style>` becomes
 * `body{color:red}` — which is why an HTML email arrived as a wall of CSS with
 * the actual message buried in it. It also loses every paragraph break, so
 * what remained was one unreadable run of words.
 *
 * Both forms are produced: the text is what the AI reads, what search matches
 * and what the conversation list previews, while the HTML is only ever shown
 * inside a sandboxed frame.
 */
class EmailBodyParser
{
    /** Elements whose contents are not prose and must be dropped entirely. */
    private const DROP_WITH_CONTENTS = ['style', 'script', 'head', 'title', 'meta', 'link', 'noscript'];

    /** Elements that can run code, frame other origins or phish. */
    private const UNSAFE_ELEMENTS = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea', 'select', 'base', 'meta', 'link'];

    /** Where a line break belongs when flattening to text. */
    private const BLOCK_ELEMENTS = ['p', 'div', 'br', 'tr', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'section', 'article', 'header', 'footer', 'pre'];

    public function looksLikeHtml(string $raw): bool
    {
        return (bool) preg_match('/<(?:html|body|head|div|p|table|br|span|img|a|style|font|center)\b[^>]*>/i', $raw);
    }

    /**
     * Readable plain text.
     *
     * Plain-text input is returned as-is: running it through the DOM parser
     * would mangle an innocent "a < b".
     */
    public function toText(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (! $this->looksLikeHtml($raw)) {
            return $this->collapseBlankLines($raw);
        }

        $document = $this->parse($raw);
        if ($document === null) {
            // Fall back to the old behaviour rather than losing the message.
            return $this->collapseBlankLines(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $xpath = new DOMXPath($document);
        foreach (self::DROP_WITH_CONTENTS as $tag) {
            /** @var DOMNode $node */
            foreach (iterator_to_array($xpath->query('//'.$tag) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $text = $this->flatten($document->documentElement ?? $document);

        return $this->collapseBlankLines(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Display-safe HTML, or null when the source was not HTML.
     *
     * This is defence in depth, not the only defence: the reader renders the
     * result inside a sandboxed frame with scripting disabled, so neither
     * layer is trusted alone.
     */
    public function toSafeHtml(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || ! $this->looksLikeHtml($raw)) {
            return null;
        }

        $document = $this->parse($raw);
        if ($document === null) {
            return null;
        }

        $xpath = new DOMXPath($document);
        foreach (self::UNSAFE_ELEMENTS as $tag) {
            foreach (iterator_to_array($xpath->query('//'.$tag) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');

                // Inline handlers are how a mail gets script into a page
                // without a <script> tag at all.
                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->nodeName);

                    continue;
                }
                if (in_array($name, ['href', 'src', 'action', 'formaction', 'background', 'xlink:href'], true)
                    && $this->isDangerousUrl($value)) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }

            // Every link leaves the app, and without this a target-less link
            // would replace the reader frame with the destination.
            if (strtolower($element->nodeName) === 'a' && $element->hasAttribute('href')) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        // Most mail keeps its styling in <head>, which is outside the body we
        // return. Dropping it would render every message as unstyled text and
        // defeat the point of showing the HTML at all. Inline CSS cannot
        // execute in the sandboxed, script-disabled frame that displays this,
        // and the frame's own policy decides what the CSS may load.
        $styles = '';
        foreach (iterator_to_array($xpath->query('//style') ?: []) as $style) {
            $styles .= '<style>'.$style->textContent.'</style>';
            $style->parentNode?->removeChild($style);
        }

        $body = $xpath->query('//body')->item(0);
        $html = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $html .= $document->saveHTML($child);
        }

        return trim($html) === '' ? null : $styles.$html;
    }

    private function isDangerousUrl(string $value): bool
    {
        // Strip the control characters and entities used to smuggle a scheme
        // past a naive prefix check, then look at what is left.
        $normalised = strtolower(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $normalised = preg_replace('/[\s\x00-\x1F\x7F]+/', '', $normalised) ?? '';

        return (bool) preg_match('/^(?:javascript|vbscript|data:text\/html|file):/i', $normalised);
    }

    private function parse(string $html): ?DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Mail is routinely malformed; the meta forces UTF-8 because
        // DOMDocument otherwise assumes ISO-8859-1 and mangles every accent.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function flatten(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '';
        }
        if ($node->nodeType !== XML_ELEMENT_NODE && $node->nodeType !== XML_DOCUMENT_NODE) {
            return '';
        }

        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROP_WITH_CONTENTS, true)) {
            return '';
        }
        if ($name === 'br') {
            return "\n";
        }

        $text = '';
        foreach ($node->childNodes ?? [] as $child) {
            $text .= $this->flatten($child);
        }

        if (in_array($name, self::BLOCK_ELEMENTS, true)) {
            return "\n".trim($text)."\n";
        }
        if ($name === 'td' || $name === 'th') {
            return $text.' ';
        }

        return $text;
    }

    private function collapseBlankLines(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
