import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ImageOff } from 'lucide-react';

/**
 * Renders a mail's own HTML.
 *
 * Mail markup is written by strangers, so it never touches the app's DOM. It
 * goes into an iframe without `allow-scripts`, which means nothing in the
 * message can execute — not a <script>, not an inline handler, not a
 * javascript: URL — regardless of what got through the server-side sanitiser.
 * `allow-same-origin` is present only so the height can be measured; on its
 * own, with scripting off, it grants the message nothing.
 *
 * Remote images start blocked. A tracking pixel is the standard way to learn
 * that a specific person opened a specific mail at a specific moment, and
 * every serious mail client refuses that by default. The policy below is what
 * enforces it — not rewritten markup — so there is nothing to slip past.
 */

const POLICY = allowImages => [
    "default-src 'none'",
    "style-src 'unsafe-inline'",
    `img-src data: ${allowImages ? 'https: http:' : ''}`.trim(),
    'font-src data:',
    // Without this a link inside the frame could navigate the frame itself.
    "form-action 'none'",
].join('; ');

const DOCUMENT_STYLES = `
    html, body { margin: 0; padding: 0; }
    body {
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        line-height: 1.6;
        color: #1f2937;
        background: #ffffff;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    img { max-width: 100%; height: auto; }
    table { max-width: 100%; }
    a { color: #8F5FA7; }
    blockquote {
        margin: 0.5em 0;
        padding-left: 0.9em;
        border-left: 3px solid #e5e7eb;
        color: #6b7280;
    }
`;

export default function EmailHtmlBody({ html, className = '' }) {
    const frameRef = useRef(null);
    const [height, setHeight] = useState(120);
    const [showImages, setShowImages] = useState(false);

    // Only offer the banner when there is actually something being withheld,
    // so a plain text-and-tables mail does not carry a control that does
    // nothing.
    const hasRemoteImages = useMemo(
        () => /<img[^>]+src\s*=\s*["']?https?:/i.test(html || ''),
        [html],
    );

    const srcDoc = useMemo(() => `<!DOCTYPE html>
<html><head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="${POLICY(showImages)}">
<base target="_blank">
<style>${DOCUMENT_STYLES}</style>
</head><body>${html || ''}</body></html>`, [html, showImages]);

    const measure = useCallback(() => {
        const frame = frameRef.current;
        try {
            const body = frame?.contentDocument?.body;
            if (!body) return;
            // scrollHeight alone misses a trailing floated element, which mail
            // built out of tables produces constantly.
            const next = Math.max(body.scrollHeight, body.offsetHeight, 40);
            setHeight(previous => (Math.abs(previous - next) > 2 ? next : previous));
        } catch {
            // A browser that denies access to the frame document leaves the
            // last known height, which is better than collapsing to nothing.
        }
    }, []);

    useEffect(() => {
        const frame = frameRef.current;
        if (!frame) return undefined;

        // Images and webfonts settle after load, so one measurement is never
        // enough; observing the body catches the reflow they cause.
        let observer;
        try {
            const body = frame.contentDocument?.body;
            if (body && typeof window.ResizeObserver !== 'undefined') {
                observer = new window.ResizeObserver(measure);
                observer.observe(body);
            }
        } catch {
            // Fall back to the load handler and the timers below.
        }

        const timers = [80, 400, 1200].map(delay => setTimeout(measure, delay));

        return () => {
            observer?.disconnect();
            timers.forEach(clearTimeout);
        };
    }, [srcDoc, measure]);

    return (
        <div className={className}>
            {hasRemoteImages && !showImages && (
                <div className="mb-2 flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                    <ImageOff className="h-3.5 w-3.5 shrink-0" />
                    <span className="flex-1">Images are blocked so the sender cannot tell when you opened this.</span>
                    <button
                        type="button"
                        onClick={() => setShowImages(true)}
                        className="rounded-lg border border-amber-300 bg-white px-2 py-1 font-semibold text-amber-900 transition hover:bg-amber-100 dark:border-amber-800 dark:bg-transparent dark:text-amber-200 dark:hover:bg-amber-900/40"
                    >
                        Show images
                    </button>
                </div>
            )}
            <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700">
                <iframe
                    ref={frameRef}
                    title="Email message"
                    srcDoc={srcDoc}
                    onLoad={measure}
                    // No allow-scripts: nothing in the message can run.
                    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    referrerPolicy="no-referrer"
                    className="w-full border-0 bg-white"
                    style={{ height: `${height}px` }}
                />
            </div>
        </div>
    );
}
