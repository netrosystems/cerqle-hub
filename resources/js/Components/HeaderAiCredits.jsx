import { Link, usePage } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';

export default function HeaderAiCredits() {
    const { headerAiCredits: credits, auth } = usePage().props;
    if (!credits || auth?.adminUser) return null;
    const byok = credits.mode === 'byok';
    const label = byok ? 'Own API' : `${credits.remaining.toLocaleString()} AI credits`;
    const detail = byok
        ? 'Using your own API provider. Cerqle credits are not consumed.'
        : `${credits.used.toLocaleString()} used · ${credits.reserved.toLocaleString()} reserved · ${credits.allowance.toLocaleString()} total. Shared across your organization.${credits.mode === 'auto_fallback' ? ' Automatic API fallback enabled.' : ''}`;
    const tone = byok ? 'text-neutral-600 dark:text-neutral-300' : credits.exhausted
        ? 'text-red-600 dark:text-red-400' : credits.warning
            ? 'text-amber-700 dark:text-amber-300' : 'text-brand-600 dark:text-brand-300';

    return <Link href={route('client.ai.providers.index')} title={detail} aria-label={`${label}. ${detail} Open AI provider settings.`}
        className={`inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-neutral-200 dark:border-neutral-700 px-2 py-1.5 text-xs font-medium tabular-nums ${tone}`}>
        <Sparkles className="h-3.5 w-3.5" aria-hidden="true" />
        <span>{label}</span>
    </Link>;
}
