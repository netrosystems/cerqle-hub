import { Head, Link, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft } from 'lucide-react';
import ChatWidgetForm from './Partials/ChatWidgetForm';

export default function ChatWidgetCreate({ chatbots = [], canUseCustomLauncherLogo = false }) {
    const submit = (payload) => {
        router.post(route('client.inbox.chat-widgets.store'), payload, { forceFormData: true });
    };

    return (
        <ClientLayout title="New website widget">
            <Head title="New website widget" />
            <div className="space-y-6">
                <div>
                    <Link href={route('client.inbox.chat-widgets.index')} className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                        <ArrowLeft className="h-4 w-4" /> Website widgets
                    </Link>
                    <h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">Create a website chat widget</h2>
                    <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                        Customize it, save, then copy the one-line snippet onto your site. Every conversation lands in your omnichannel inbox.
                    </p>
                </div>

                <ChatWidgetForm chatbots={chatbots} canUseCustomLauncherLogo={canUseCustomLauncherLogo} submitLabel="Create widget" onSubmit={submit} />
            </div>
        </ClientLayout>
    );
}
