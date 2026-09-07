import { usePage } from '@inertiajs/react';

export default function BillingUsageOverview() {
    const { channel_plan_usage = {} } = usePage().props;
    const items = Object.values(channel_plan_usage);
    if (!items.length) return null;

    return <section aria-label="Plan usage" className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/50 p-4">
        <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Plan usage</h2>
        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Across all workspaces · Messages reset monthly; other counts show current connections and widgets.</p>
        <div className="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
            {items.map(item => {
                const percent = item.limit > 0 ? Math.min(100, Math.max(0, item.used / item.limit * 100)) : 0;
                const full = !item.unlimited && (item.is_full || item.limit === 0);
                const color = full ? 'bg-red-500' : percent >= 80 ? 'bg-amber-500' : 'bg-brand-500';
                return <div key={item.key}>
                    <div className="flex justify-between gap-2 text-xs">
                        <span className="text-neutral-600 dark:text-neutral-300">{item.label}</span>
                        <span className="font-semibold tabular-nums text-neutral-900 dark:text-neutral-100">{item.used.toLocaleString()} / {item.unlimited ? 'Unlimited' : item.limit.toLocaleString()}</span>
                    </div>
                    {!item.unlimited && <div role="progressbar" aria-label={item.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} className="mt-2 h-1.5 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-700">
                        <div className={`h-full rounded-full ${color}`} style={{ width: `${full ? 100 : percent}%` }} />
                    </div>}
                    <p className={`mt-1 text-xs ${full ? 'text-red-600 dark:text-red-400' : 'text-neutral-500 dark:text-neutral-400'}`}>
                        {item.unlimited ? 'No plan cap' : item.limit === 0 ? 'Not included' : full ? 'Limit reached' : `${item.remaining.toLocaleString()} remaining`}
                    </p>
                </div>;
            })}
        </div>
    </section>;
}
