import { Check, Copy, ExternalLink, Smartphone } from 'lucide-react';
import useCopy from '@/hooks/useCopy';

/**
 * Mobile install. The same conversations as the website widget, reached from a
 * native app instead of a page.
 *
 * The SDK has its own key so its availability can be controlled independently
 * from the website embed while both surfaces still land in one inbox.
 */
const PLATFORMS = [
    {
        name: 'Flutter',
        status: 'available',
        detail: 'iOS and Android · pub.dev',
        href: 'https://pub.dev/packages/cerqle_chat',
    },
    { name: 'Kotlin', status: 'soon', detail: 'Android, native' },
    { name: 'React Native', status: 'soon', detail: 'iOS and Android' },
    { name: 'Swift', status: 'soon', detail: 'iOS, native' },
];

export default function MobileSdkCard({ sdkWidgetKey }) {
    const { copied, copy } = useCopy();

    return (
        <div className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-5">
            <h3 className="flex items-center gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100">
                <Smartphone className="h-4 w-4 text-brand-500" /> Mobile SDK
            </h3>
            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                Chats from your app arrive in the same inbox as your website, under the same widget settings.
            </p>

            <div className="mt-4">
                <label htmlFor="sdk-key" className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">
                    SDK key
                </label>
                <div className="mt-1.5 flex items-stretch gap-2">
                    <input
                        id="sdk-key"
                        readOnly
                        value={sdkWidgetKey ?? ''}
                        onFocus={(e) => e.target.select()}
                        className="w-full flex-1 rounded-lg border border-neutral-800 bg-neutral-950 px-3 py-2.5 font-mono text-[12px] text-green-400 focus:outline-none focus:ring-2 focus:ring-brand-500/40"
                    />
                    <button
                        type="button"
                        onClick={() => copy(sdkWidgetKey)}
                        className="flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-brand-600 px-3 text-xs font-semibold text-white transition hover:bg-brand-700"
                    >
                        {copied ? (
                            <><Check className="h-3.5 w-3.5" /> Copied</>
                        ) : (
                            <><Copy className="h-3.5 w-3.5" /> Copy</>
                        )}
                    </button>
                </div>
                <p className="mt-1.5 text-xs text-neutral-400">
                    Use this key only in the customer mobile SDK. The website snippet keeps its existing widget key.
                </p>
            </div>

            <p className="mt-5 text-xs font-medium uppercase tracking-wide text-neutral-400">Platforms</p>
            <ul className="mt-2 grid gap-2 sm:grid-cols-2">
                {PLATFORMS.map(({ name, status, detail, href }) => {
                    const available = status === 'available';

                    return (
                        <li
                            key={name}
                            className={`flex items-center justify-between gap-3 rounded-xl border p-3 ${
                                available
                                    ? 'border-brand-200 bg-brand-50/50 dark:border-brand-800 dark:bg-brand-900/10'
                                    : 'border-neutral-200 bg-neutral-50/60 dark:border-neutral-800 dark:bg-neutral-900/40'
                            }`}
                        >
                            <div className="min-w-0">
                                <p className={`text-sm font-semibold ${available ? 'text-neutral-900 dark:text-neutral-100' : 'text-neutral-500 dark:text-neutral-400'}`}>
                                    {name}
                                </p>
                                <p className="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">{detail}</p>
                            </div>
                            {available ? (
                                <a
                                    href={href}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex flex-shrink-0 items-center gap-1.5 rounded-full border border-brand-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-700 transition hover:bg-brand-50 dark:border-brand-700 dark:bg-neutral-900 dark:text-brand-300"
                                >
                                    Get it <ExternalLink className="h-3 w-3" />
                                </a>
                            ) : (
                                <span className="flex-shrink-0 rounded-full border border-neutral-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-neutral-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-400">
                                    Coming soon
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
