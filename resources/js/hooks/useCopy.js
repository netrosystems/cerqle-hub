import { useState } from 'react';

/**
 * Copy-to-clipboard with a short "Copied" acknowledgement.
 *
 * The fallback matters: the async clipboard API is unavailable on insecure
 * origins and in some embedded webviews, and a copy button that silently does
 * nothing is worse than no button at all.
 */
export default function useCopy(resetMs = 2200) {
    const [copied, setCopied] = useState(false);

    const copy = (text) => {
        const done = () => {
            setCopied(true);
            setTimeout(() => setCopied(false), resetMs);
        };

        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text).then(done, done);

            return;
        }

        const area = document.createElement('textarea');
        area.value = text;
        area.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
        } catch {
            // Nothing else to try; the value is on screen to copy by hand.
        }
        document.body.removeChild(area);
        done();
    };

    return { copied, copy };
}
