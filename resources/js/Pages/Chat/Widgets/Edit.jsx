import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft } from 'lucide-react';
import ChatWidgetForm from './Partials/ChatWidgetForm';
import InstallCard from './Partials/InstallCard';
import IdentityCard from './Partials/IdentityCard';
import MobileSdkCard from './Partials/MobileSdkCard';

const TABS = [
    { id: 'configuration', label: 'Configuration' },
    { id: 'setup', label: 'Setup' },
];

export default function ChatWidgetEdit({ widget, chatbots = [], aiTimezone, embedBase, identitySecret, canUseCustomLauncherLogo = false }) {
    const [tab, setTab] = useState('configuration');

    const submit = (payload) => {
        // preserveState keeps the open tab after a save; without it the page
        // remounts on Configuration, which is jarring when you saved from there
        // and looks like the tab reset itself.
        router.post(route('client.inbox.chat-widgets.update', widget.id), { ...payload, _method: 'put' }, { preserveScroll: true, preserveState: true, forceFormData: true });
    };

    // On/off saves on its own rather than waiting for the settings form, and
    // preserveState keeps the open tab and any unsaved edits intact.
    const setEnabled = (value) => {
        router.patch(route('client.inbox.chat-widgets.enabled', widget.id), { enabled: value }, { preserveScroll: true, preserveState: true });
    };

    // Add the SDK switch here when it exists:
    //   { key: 'sdk', label: 'SDK', checked: widget.sdk_enabled, onChange: setSdkEnabled }
    const headerSwitches = [
        { key: 'widget', label: 'Widget', checked: !!widget.enabled, onChange: setEnabled },
    ];

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
                    {/* Switch group. A second switch (SDK) sits beside this one:
                        add it to `headerSwitches` and the row grows. */}
                    <div className="flex flex-shrink-0 flex-wrap items-center gap-2">
                        {headerSwitches.map(({ key, label, checked, onChange }) => (
                            <div
                                key={key}
                                className="flex items-center gap-2.5 rounded-xl border border-neutral-200 bg-white px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900"
                            >
                                <span className="text-xs font-semibold text-neutral-700 dark:text-neutral-300">{label}</span>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={checked}
                                    aria-label={label}
                                    onClick={() => onChange(!checked)}
                                    className="relative inline-flex h-5 w-9 flex-shrink-0 items-center rounded-full transition focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                                >
                                    <span className={`absolute inset-0 rounded-full transition ${checked ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-700'}`} />
                                    <span className={`relative inline-block h-4 w-4 transform rounded-full bg-white transition ${checked ? 'translate-x-4' : 'translate-x-0.5'}`} />
                                </button>
                                <span className={`text-[11px] font-semibold ${checked ? 'text-emerald-600 dark:text-emerald-400' : 'text-neutral-400'}`}>
                                    {checked ? 'On' : 'Off'}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                <div role="tablist" aria-label="Widget sections" className="flex flex-wrap items-center gap-2">
                    {TABS.map(({ id, label }) => (
                        <button
                            key={id}
                            type="button"
                            role="tab"
                            id={`wtab-${id}`}
                            aria-selected={tab === id}
                            aria-controls={`wpanel-${id}`}
                            onClick={() => setTab(id)}
                            className={`rounded-full border px-3.5 py-1.5 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-brand-500/30 ${
                                tab === id
                                    ? 'border-brand-200 bg-brand-50 text-brand-700 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300'
                                    : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {/* Both panels stay mounted and the inactive one is hidden, so a
                    half-filled form is never thrown away by switching tabs. */}
                <div role="tabpanel" id="wpanel-configuration" aria-labelledby="wtab-configuration" hidden={tab !== 'configuration'}>
                    <ChatWidgetForm widget={widget} chatbots={chatbots} aiTimezone={aiTimezone} canUseCustomLauncherLogo={canUseCustomLauncherLogo} submitLabel="Save changes" onSubmit={submit} />
                </div>

                <div role="tabpanel" id="wpanel-setup" aria-labelledby="wtab-setup" hidden={tab !== 'setup'} className="space-y-8">
                    {/* Two install paths, one widget. Grouped under headings so it
                        is obvious the mobile SDK is not a separate product and
                        shares these settings and this inbox. */}
                    <section className="space-y-4">
                        <div>
                            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Website</h3>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                One line of JavaScript on the pages where you want the widget.
                            </p>
                        </div>
                        <InstallCard embedBase={embedBase} widgetKey={widget.widget_key} title="Website snippet">
                            <IdentityCard embedded embedBase={embedBase} widgetKey={widget.widget_key} identitySecret={identitySecret} verification={widget.identity_verification} />
                        </InstallCard>
                    </section>

                    <section className="space-y-4">
                        <div>
                            <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Mobile app</h3>
                            <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                Use the SDK key below — it is the same key that appears in your website snippet.
                            </p>
                        </div>
                        <MobileSdkCard widgetKey={widget.widget_key} />
                    </section>
                </div>
            </div>
        </ClientLayout>
    );
}
