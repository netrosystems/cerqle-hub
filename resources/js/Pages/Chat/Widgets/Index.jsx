import { Head, Link, router, usePage } from '@inertiajs/react';
import { useConfirm } from '@/Components/ui/ConfirmProvider';
import ClientLayout from '@/Layouts/ClientLayout';
import { Plus, Pencil, Trash2, Check, Bot, MessageCircle, Globe, Power } from 'lucide-react';
import InstallCard from './Partials/InstallCard';

export default function ChatWidgetIndex({ widgets = [], embedBase, aiAvailability = {}, defaultPrimaryColor = '#8F5FA7' }) {
    const confirm = useConfirm();
    const flash = usePage().props.flash ?? {};

    const remove = async (id) => {
        if (await confirm('Delete this widget? The embed will stop working. Past conversations stay in your inbox.')) {
            router.delete(route('client.inbox.chat-widgets.destroy', id), { preserveScroll: true });
        }
    };

    return (
        <ClientLayout title="Website widget">
            <Head title="Website chat widget" />
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Website chat widget</h2>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                            Add a live-chat bubble to your website. Conversations sync into your omnichannel inbox — with optional AI answering.
                        </p>
                    </div>
                    <Link href={route('client.inbox.chat-widgets.create')} className="flex flex-shrink-0 items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 transition">
                        <Plus className="h-4 w-4" /> New widget
                    </Link>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 px-4 py-3 text-sm flex items-center gap-2">
                        <Check className="h-4 w-4 flex-shrink-0" /> {flash.success}
                    </div>
                )}

                {widgets.length === 0 ? (
                    <EmptyJourney />
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {widgets.map((w) => (
                            <div key={w.id} className="flex flex-col gap-4 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-5">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="flex items-center gap-3 min-w-0">
                                        <span className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full text-white" style={{ background: w.primary_color || defaultPrimaryColor }}>
                                            <MessageCircle className="h-5 w-5" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-sm text-neutral-900 dark:text-neutral-100">{w.name || w.title || 'Website chat'}</p>
                                            <div className="mt-1 flex flex-wrap gap-1.5">
                                                <Badge on={w.enabled} icon={<Power className="h-3 w-3" />}>{w.enabled ? 'Live' : 'Off'}</Badge>
                                                <Badge tone="violet" icon={<Bot className="h-3 w-3" />}>AI {w.ai_mode || (w.ai_enabled ? 'permanent' : 'off')}{w.ai_mode !== 'off' ? ` · ${aiAvailability[w.id]?.active ? 'active' : 'inactive'}` : ''}</Badge>
                                                {w.allowed_domains?.length > 0 && <Badge tone="amber" icon={<Globe className="h-3 w-3" />}>{w.allowed_domains.length} domain{w.allowed_domains.length > 1 ? 's' : ''}</Badge>}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex flex-shrink-0 items-center gap-1">
                                        <Link href={route('client.inbox.chat-widgets.edit', w.id)} className="rounded-lg p-1.5 text-neutral-400 hover:bg-brand-50 hover:text-brand-600 dark:hover:bg-brand-900/20 transition" title="Edit">
                                            <Pencil className="h-4 w-4" />
                                        </Link>
                                        <button onClick={() => remove(w.id)} className="rounded-lg p-1.5 text-neutral-400 hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20 transition" title="Delete">
                                            <Trash2 className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                                <InstallCard embedBase={embedBase} widgetKey={w.widget_key} compact />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}

function Badge({ children, icon, tone = 'green', on = true }) {
    const tones = {
        green: on ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800',
        violet: 'bg-violet-50 text-violet-700 dark:bg-violet-900/30 dark:text-violet-300',
        amber: 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    };
    return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>{icon}{children}</span>;
}

function EmptyJourney() {
    const steps = [
        { icon: <Pencil className="h-5 w-5" />, title: 'Create a widget', desc: 'Pick your colors, greeting and agent name — with a live preview.' },
        { icon: <MessageCircle className="h-5 w-5" />, title: 'Paste one line', desc: 'Drop the snippet on your site. The chat bubble appears instantly.' },
        { icon: <Bot className="h-5 w-5" />, title: 'Reply — or let AI', desc: 'Chats land in your inbox. Answer live, or switch on an AI chatbot.' },
    ];
    return (
        <div className="rounded-2xl border border-dashed border-neutral-300 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-900/40 p-8 text-center">
            <span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                <MessageCircle className="h-7 w-7" />
            </span>
            <h3 className="mt-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100">Turn website visitors into conversations</h3>
            <p className="mx-auto mt-1 max-w-md text-sm text-neutral-500 dark:text-neutral-400">
                A single-line embed adds a live-chat bubble to your site. Every message flows into your omnichannel inbox.
            </p>
            <div className="mx-auto mt-8 grid max-w-3xl gap-4 sm:grid-cols-3">
                {steps.map((s, i) => (
                    <div key={i} className="rounded-xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-4 text-left">
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600 dark:text-brand-400">{s.icon}</span>
                        <p className="mt-3 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{i + 1}. {s.title}</p>
                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{s.desc}</p>
                    </div>
                ))}
            </div>
            <Link href={route('client.inbox.chat-widgets.create')} className="mt-8 inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 transition">
                <Plus className="h-4 w-4" /> Create your first widget
            </Link>
        </div>
    );
}
