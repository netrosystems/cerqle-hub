import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Info } from 'lucide-react';
import Tooltip from '@/Components/ui/Tooltip';

const labels = {
    rag_reply: 'Chatbot / RAG reply', email_subject: 'Email subject suggestions',
    short_rewrite: 'Short rewrite', automation_ai_step: 'Automation AI step',
    email_compose: 'Full email', social_single_generate: 'Single social post',
    automation_workflow_generate: 'Workflow generation', social_plan_generate: 'Multi-post plan',
};

export default function AiProviderOverview({ mode, credits, rates = {}, enforced, error }) {
    const [saving, setSaving] = useState(false);
    const groups = Object.entries(rates).reduce((result, [key, cost]) => {
        (result[cost] ??= []).push(labels[key] ?? key);
        return result;
    }, {});
    const percent = Math.max(0, Math.min(100, credits?.percent_used ?? 0));
    return <section className="rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-neutral-200 px-4 py-2.5 dark:border-neutral-700">
            <div>
                <div className="flex items-center gap-2">
                    <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">AI provider</h3>
                    <Tooltip position="right" wrap content="Credits are shared across your organization. This provider choice applies to the current workspace. Automatic fallback requires an active, tested API key.">
                        <button type="button" aria-label="About AI provider modes" className="text-neutral-400 hover:text-brand-500"><Info className="h-4 w-4" /></button>
                    </Tooltip>
                    {!enforced && <span title="Usage is tracked, but credit exhaustion is not currently enforced." className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">Tracking only</span>}
                </div>
            </div>
            {credits && <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <div className="flex items-baseline gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                    <strong className="tabular-nums text-neutral-900 dark:text-neutral-100">{credits.remaining.toLocaleString()}</strong>
                    <span>/ {credits.allowance.toLocaleString()} credits left</span>
                </div>
                <div role="progressbar" aria-label="AI credit usage" aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent} className="h-1 w-16 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                    <div className={`h-full ${percent >= 100 ? 'bg-red-500' : percent >= 80 ? 'bg-amber-500' : 'bg-brand-500'}`} style={{ width: `${percent}%` }} />
                </div>
                {credits.resets_at && <span className="text-[11px] text-neutral-500 dark:text-neutral-400">Resets {new Date(credits.resets_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</span>}
            </div>}
        </div>
        <div className="p-4">
            <div role="group" aria-label="AI provider mode" className="grid gap-2 lg:grid-cols-3">
                {[
                    ['managed', 'Cerqle credits', 'Use included credits. No API key needed.'],
                    ['auto_fallback', 'Cerqle credits, then my provider', 'Use your API key after included credits run out.'],
                    ['byok', 'My provider only', 'Your provider bills you; no Cerqle credits used.'],
                ].map(([value, title, description]) => <button key={value} type="button" title={description} aria-pressed={mode === value} disabled={saving}
                    onClick={() => { setSaving(true); router.put(route('client.ai.providers.mode'), { mode: value }, { preserveScroll: true, onFinish: () => setSaving(false) }); }}
                    className={`rounded-lg border p-3 text-left transition disabled:opacity-60 ${mode === value ? 'border-brand-500 bg-brand-50 dark:bg-brand-950/30' : 'border-neutral-200 hover:border-brand-300 dark:border-neutral-700'}`}>
                    <span className="flex items-start gap-2 text-sm font-semibold text-neutral-900 dark:text-neutral-100"><span aria-hidden="true" className={`mt-1 h-3 w-3 shrink-0 rounded-full border ${mode === value ? 'border-brand-500 bg-brand-500' : 'border-neutral-400'}`} />{title}</span>
                </button>)}
            </div>
            {error && <p role="alert" className="mt-2 text-xs text-red-600">{error}</p>}
        </div>
        <details className="border-t border-neutral-200 dark:border-neutral-700">
            <summary className="cursor-pointer px-4 py-3 text-xs font-medium text-neutral-600 dark:text-neutral-300">Credit costs</summary>
            <div className="px-4 pb-4">
                <div className="grid gap-3 sm:grid-cols-3">
                    {Object.entries(groups).map(([cost, actions]) => <div key={cost} className="rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800/60">
                        <h4 className="text-sm font-semibold text-brand-600 dark:text-brand-300">{cost} {Number(cost) === 1 ? 'credit' : 'credits'}</h4>
                        <ul className="mt-2 space-y-1 text-xs text-neutral-600 dark:text-neutral-300">{actions.map(action => <li key={action}>{action}</li>)}</ul>
                    </div>)}
                </div>
                <p className="mt-3 text-xs text-neutral-500 dark:text-neutral-400">Human replies, embeddings and provider connection tests cost 0 credits. Failed actions release their reservation; internal retries do not add a second charge. Credits reset monthly with no rollover.</p>
                <p className="mt-2 text-xs text-neutral-500 dark:text-neutral-400">DeepSeek uses your own API key only and may process data in China. Review its privacy terms before use.</p>
            </div>
        </details>
    </section>;
}
