import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, Check, Trash2 } from 'lucide-react';
import ChatWidgetForm from './Partials/ChatWidgetForm';
import InstallCard from './Partials/InstallCard';
import IdentityCard from './Partials/IdentityCard';

export default function ChatWidgetEdit({ widget, chatbots = [], embedBase, identitySecret, canUseCustomLauncherLogo = false }) {
    const flash = usePage().props.flash ?? {};

    const submit = (payload) => {
        router.post(route('client.inbox.chat-widgets.update', widget.id), { ...payload, _method: 'put' }, { preserveScroll: true, forceFormData: true });
    };

    const remove = () => {
        if (confirm('Delete this widget? The embed will stop working. Past conversations stay in your inbox.')) {
            router.delete(route('client.inbox.chat-widgets.destroy', widget.id));
        }
    };

    return (
        <ClientLayout title="Edit website widget">
            <Head title="Edit website widget" />
            <div className="space-y-6">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <Link href={route('client.inbox.chat-widgets.index')} className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                            <ArrowLeft className="h-4 w-4" /> Website widgets
                        </Link>
                        <h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">{widget.name || 'Website chat widget'}</h2>
                    </div>
                    <button onClick={remove} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-700 px-3 py-2 text-sm text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <Trash2 className="h-4 w-4" /> Delete
                    </button>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 px-4 py-3 text-sm flex items-center gap-2">
                        <Check className="h-4 w-4 flex-shrink-0" /> {flash.success}
                    </div>
                )}

                <InstallCard embedBase={embedBase} widgetKey={widget.widget_key} />

                <IdentityCard embedBase={embedBase} widgetKey={widget.widget_key} identitySecret={identitySecret} verification={widget.identity_verification} />

                <ChatWidgetForm widget={widget} chatbots={chatbots} canUseCustomLauncherLogo={canUseCustomLauncherLogo} submitLabel="Save changes" onSubmit={submit} />
            </div>
        </ClientLayout>
    );
}
