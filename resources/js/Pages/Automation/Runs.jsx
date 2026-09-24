import { Head, Link, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, CheckCircle, XCircle, Clock, SkipForward, RotateCcw } from 'lucide-react';
import { useConfirm } from '@/Components/ui/ConfirmProvider';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const STATUS_ICONS = {
    completed: <CheckCircle className="h-4 w-4 text-green-500" />,
    failed:    <XCircle className="h-4 w-4 text-red-500" />,
    running:   <Clock className="h-4 w-4 text-blue-500" />,
    pending:   <Clock className="h-4 w-4 text-yellow-500" />,
    waiting:   <Clock className="h-4 w-4 text-amber-500" />,
    cancelled: <SkipForward className="h-4 w-4 text-neutral-400" />,
};

const LOG_COLORS = {
    ok:      'text-green-700 bg-green-50 dark:bg-green-900/20 dark:text-green-300',
    error:   'text-red-700 bg-red-50 dark:bg-red-900/20 dark:text-red-300',
    skipped: 'text-neutral-500 bg-neutral-50 dark:bg-neutral-800 dark:text-neutral-400',
};

export default function AutomationRuns({ automation, runs }) {
    const { t } = useTranslation();
    const confirm = useConfirm();
    const [retrying, setRetrying] = useState(null);

    // A step that may already have reached the customer is only retried once
    // someone confirms they checked the chat — otherwise it could send twice.
    const retry = async (run) => {
        if (run.retry?.needs_confirmation) {
            const ok = await confirm(run.retry.reason, { confirmLabel: t('automation.retry_anyway', 'Retry anyway'), destructive: false });
            if (!ok) return;
        }
        setRetrying(run.id);
        router.post(
            route('client.automations.runs.retry', [automation.uuid, run.id]),
            { confirmed: Boolean(run.retry?.needs_confirmation) },
            { preserveScroll: true, onFinish: () => setRetrying(null) },
        );
    };
    return (
        <ClientLayout title={`${automation.name} · ${t('automation.runs')}`}>
            <Head title={`${t('automation.runs')} · ${automation.name}`} />
            <div className="space-y-5 max-w-4xl">
                <div className="flex items-center gap-3">
                    <Link href={route('client.automations.index')} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <ArrowLeft className="h-5 w-5" />
                    </Link>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{automation.name} — {t('automation.runs')}</h2>
                </div>

                <div className="space-y-3">
                    {runs.data.map(run => (
                        <div key={run.id} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 space-y-3">
                            <div className="flex items-center gap-3">
                                {STATUS_ICONS[run.status]}
                                <span className="font-medium text-neutral-900 dark:text-neutral-100 text-sm">{t('automation.run_number', { id: run.id })}</span>
                                <span className="text-xs">{run.status} {run.wake_at ? `· next check ${run.wake_at}` : ''}</span>
                                {run.status === 'waiting' && <span className="text-xs">{run.context?._awaiting_reply ? t('automation.waiting_reply', 'Waiting for reply') : t('automation.waiting_delay', 'Scheduled delay')} · {run.resume_node_id}</span>}
                                <span className="text-xs text-neutral-400">{run.started_at}</span>
                                {run.error && <span className="ml-auto text-xs text-red-600 dark:text-red-400">{run.error}</span>}
                            </div>
                            {run.retry && (
                                <div className="flex flex-wrap items-center gap-2 border-t border-neutral-100 pt-3 dark:border-neutral-800">
                                    {run.retry.retryable ? (
                                        <button
                                            type="button"
                                            onClick={() => retry(run)}
                                            disabled={retrying === run.id}
                                            className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-700 transition hover:border-brand-300 hover:text-brand-700 disabled:opacity-50 dark:border-neutral-600 dark:text-neutral-200"
                                        >
                                            <RotateCcw className={`h-3.5 w-3.5 ${retrying === run.id ? 'animate-spin' : ''}`} />
                                            {t('automation.retry_from_step', 'Retry from failed step')}
                                        </button>
                                    ) : null}
                                    {/* Say why before the click, not after: a refused retry or one that needs checking should never be a surprise. */}
                                    {run.retry.reason && (
                                        <span className={`text-xs ${run.retry.retryable ? 'text-amber-700 dark:text-amber-300' : 'text-neutral-500 dark:text-neutral-400'}`}>{run.retry.reason}</span>
                                    )}
                                </div>
                            )}
                            {run.logs?.length > 0 && (
                                <div className="space-y-1">
                                    {run.logs.map(log => (
                                        <div key={log.id} className={`rounded px-3 py-1.5 text-xs flex items-start gap-2 ${LOG_COLORS[log.result] ?? ''}`}>
                                            <span className="font-mono font-semibold w-24 shrink-0">{log.node_type}</span>
                                            <span>{log.message}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                    {runs.data.length === 0 && (
                        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 py-10 text-center text-neutral-400">
                            {t('automation.no_runs_yet')}
                        </div>
                    )}
                </div>
                <nav aria-label="Run history pages" className="flex gap-2">{runs.links?.map((link, i) => link.url ? <Link key={i} href={link.url} className={link.active ? 'font-bold' : ''}>{link.label.replace(/&laquo;|&raquo;|<[^>]+>/g, '')}</Link> : <span key={i} className="opacity-40">{link.label.replace(/&laquo;|&raquo;|<[^>]+>/g, '')}</span>)}</nav>
            </div>
        </ClientLayout>
    );
}
