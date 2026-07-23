import { Head, router, usePage } from '@inertiajs/react';
import { Check, Code2, CreditCard, LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Button } from '@/Components/ui';

export default function AddonsIndex({ addon, subscription, gateways = [], can_manage = false }) {
    const { flash } = usePage().props;
    const [loadingGateway, setLoadingGateway] = useState(null);

    const checkout = (gateway) => {
        setLoadingGateway(gateway);
        router.post(route('client.addons.developer-tools.checkout'), { gateway }, {
            preserveScroll: true,
            onFinish: () => setLoadingGateway(null),
        });
    };

    const cancel = () => {
        if (!window.confirm('Cancel Developer Tools access? API calls and outbound webhook deliveries will stop immediately.')) return;
        router.delete(route('client.addons.developer-tools.destroy'), { preserveScroll: true });
    };

    const price = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: addon.currency,
        minimumFractionDigits: 0,
    }).format(addon.price_cents / 100);

    return (
        <ClientLayout title="Add-ons">
            <Head title="Add-ons" />
            <div className="mx-auto max-w-4xl space-y-6">
                <div>
                    <h1 className="text-2xl font-bold text-neutral-900 dark:text-white">Add-ons</h1>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        Add optional capabilities without changing your main Cerqle plan.
                    </p>
                </div>

                {flash?.success && <div className="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">{flash.success}</div>}
                {flash?.error && <div className="rounded-lg bg-coral-50 px-4 py-3 text-sm text-coral-800 dark:bg-coral-900/20 dark:text-coral-200">{flash.error}</div>}

                <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-800/60">
                    <div className="grid gap-6 p-6 md:grid-cols-[minmax(0,1fr)_20rem]">
                        <div className="min-w-0">
                            <div className="flex items-center gap-3">
                                <span className="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-400">
                                    <Code2 className="h-5 w-5" />
                                </span>
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-lg font-semibold text-neutral-900 dark:text-white">{addon.name}</h2>
                                        {subscription?.active && <span className="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700 dark:bg-green-900/30 dark:text-green-300">Active</span>}
                                        {subscription && !subscription.active && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium capitalize text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">{subscription.status.replace('_', ' ')}</span>}
                                    </div>
                                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{addon.description}</p>
                                </div>
                            </div>

                            <ul className="mt-5 grid gap-x-5 gap-y-2 text-sm text-neutral-700 dark:text-neutral-300 sm:grid-cols-2">
                                {['API token management', 'Outbound webhooks', 'Complete API documentation'].map((feature) => (
                                    <li key={feature} className="flex items-center gap-2"><Check className="h-4 w-4 shrink-0 text-green-500" />{feature}</li>
                                ))}
                            </ul>
                        </div>

                        <div className="min-w-0 border-t border-neutral-200 pt-5 md:border-l md:border-t-0 md:pl-6 md:pt-0 dark:border-neutral-700">
                            <div className="text-3xl font-bold text-neutral-900 dark:text-white">{price}<span className="text-sm font-normal text-neutral-500">/{addon.interval}</span></div>

                            {subscription?.active ? (
                                <div className="mt-4 space-y-3">
                                    <p className="text-xs text-neutral-500">Paid with {subscription.gateway}. Developer tools are available to this client’s team.</p>
                                    {can_manage && <Button type="button" variant="outline" size="sm" className="w-full" onClick={cancel}>Cancel add-on</Button>}
                                </div>
                            ) : can_manage ? (
                                <div className="mt-4 space-y-2">
                                    {gateways.map((gateway) => (
                                        <Button key={gateway.key} type="button" size="sm" className="w-full" disabled={loadingGateway !== null} onClick={() => checkout(gateway.key)}>
                                            {loadingGateway === gateway.key ? <LoaderCircle className="mr-2 h-4 w-4 animate-spin" /> : <CreditCard className="mr-2 h-4 w-4" />}
                                            Pay with {gateway.name}
                                        </Button>
                                    ))}
                                    {gateways.length === 0 && <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">No add-on payment gateway is configured yet. Ask the super admin to configure Stripe, PayPal, or Paddle.</p>}
                                </div>
                            ) : (
                                <p className="mt-4 text-xs text-neutral-500">Only a client administrator can purchase this add-on.</p>
                            )}
                        </div>
                    </div>
                </section>
            </div>
        </ClientLayout>
    );
}
