import { useState } from 'react';
import { Check, Copy, Code, ExternalLink } from 'lucide-react';

/**
 * The install journey: the one-line embed snippet + copy button + 3 steps.
 * Shown on the Edit page and each widget card so setup is obvious.
 */
/**
 * `children` is an optional extra section inside the same card — used on the
 * Setup tab so identity passthrough reads as part of installing the widget
 * rather than a second, unrelated thing to do.
 */
export default function InstallCard({ embedBase, widgetKey, compact = false, title = 'Install on your website', children = null }) {
    const snippet = `<script src="${embedBase}/widgets/chat/${widgetKey}.js" async></script>`;
    const [copied, setCopied] = useState(false);

    const copy = () => {
        const done = () => { setCopied(true); setTimeout(() => setCopied(false), 2200); };
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(snippet).then(done, done);
        } else {
            const ta = document.createElement('textarea');
            ta.value = snippet; ta.style.cssText = 'position:fixed;opacity:0';
            document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
            done();
        }
    };

    return (
        <div className={`rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 ${compact ? 'p-4' : 'p-5'}`}>
            <h3 className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                <Code className="h-4 w-4 text-brand-500" /> {title}
            </h3>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Paste this one line just before the closing <code className="rounded bg-neutral-100 dark:bg-neutral-800 px-1">&lt;/body&gt;</code> tag on every page.
            </p>

            <div className="mt-3 flex items-stretch gap-2">
                <code className="flex-1 overflow-x-auto rounded-lg bg-neutral-950 px-3 py-2.5 text-[12px] font-mono text-green-400 whitespace-nowrap">
                    {snippet}
                </code>
                <button onClick={copy} className="flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white hover:bg-brand-700 transition">
                    {copied ? <><Check className="h-3.5 w-3.5" /> Copied</> : <><Copy className="h-3.5 w-3.5" /> Copy</>}
                </button>
            </div>

            {!compact && (
                <ol className="mt-4 space-y-2.5 text-sm">
                    {[
                        ['Copy the snippet', 'One line — no build tools, no dependencies.'],
                        ['Paste it into your site', 'In your theme footer, or via Google Tag Manager / your site builder.'],
                        ['Reply from your inbox', 'New chats arrive in the omnichannel inbox with a “Website” badge — reply like any other channel.'],
                    ].map(([title, desc], i) => (
                        <li key={i} className="flex gap-3">
                            <span className="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-brand-500/15 text-xs font-bold text-brand-600 dark:text-brand-400">{i + 1}</span>
                            <span><b className="text-neutral-800 dark:text-neutral-200">{title}.</b> <span className="text-neutral-500 dark:text-neutral-400">{desc}</span></span>
                        </li>
                    ))}
                </ol>
            )}

            <a href={`/widgets/chat/${widgetKey}.js`} target="_blank" rel="noopener" className="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 dark:text-brand-400 hover:underline">
                <ExternalLink className="h-3.5 w-3.5" /> View the generated script
            </a>

            {children && (
                <div className="mt-5 border-t border-neutral-200 pt-5 dark:border-neutral-800">{children}</div>
            )}
        </div>
    );
}
