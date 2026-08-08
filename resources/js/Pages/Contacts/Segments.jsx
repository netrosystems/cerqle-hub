import { Head, useForm, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { useState } from 'react';
import { Plus, Trash2, Filter } from 'lucide-react';
import { useTranslation } from 'react-i18next';

export default function ContactsSegments({ segments }) {
    const { t } = useTranslation();
    const [showCreate, setShowCreate] = useState(false);
    const { data, setData, post, processing, reset } = useForm({
        name: '',
    });

    const submitCreate = (e) => {
        e.preventDefault();
        post(route('client.segments.store'), { onSuccess: () => { reset(); setShowCreate(false); } });
    };

    const handleDelete = (id) => {
        if (confirm(t('contacts_page.seg_confirm_delete'))) {
            router.delete(route('client.segments.destroy', id), { preserveScroll: true });
        }
    };

    return (
        <ClientLayout title={t('contacts_page.segments')}>
            <Head title={t('contacts_page.segments')} />
            <div className="space-y-5">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Contact Lists</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('contacts_page.seg_subtitle')}</p>
                    </div>
                    {(
                        <button type="button" onClick={() => setShowCreate(true)} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                            <Plus className="h-4 w-4" /> {t('contacts_page.seg_new')}
                        </button>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {segments.map(seg => (
                        <div key={seg.id} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 flex flex-col gap-2">
                            <div className="flex items-start justify-between gap-2">
                                <span className="font-semibold text-neutral-900 dark:text-neutral-100">{seg.name}</span>
                            </div>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('contacts_page.seg_contact_count', { count: seg.contact_count })}</p>
                            <div className="flex gap-2 mt-auto pt-2 border-t border-neutral-100 dark:border-neutral-800">
                                <a href={route('client.segments.contacts', seg.id)} className="flex-1 text-center text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                                    Add Contacts →
                                </a>
                                <button type="button" onClick={() => handleDelete(seg.id)} className="text-neutral-400 hover:text-red-500 transition"><Trash2 className="h-4 w-4" /></button>
                            </div>
                        </div>
                    ))}
                    {segments.length === 0 && (
                        <div className="col-span-3">
                            <EmptyState
                                icon={<Filter className="h-8 w-8" />}
                                title={t('contacts_page.seg_empty_title')}
                                description={t('contacts_page.seg_empty_description')}
                                action={{ label: t('contacts_page.seg_new'), onClick: () => setShowCreate(true) }}
                            />
                        </div>
                    )}
                </div>
            </div>

            {showCreate && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4 max-h-[90vh] overflow-y-auto">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.seg_new')}</h3>
                        <form onSubmit={submitCreate} className="space-y-4">
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('common.name')}</label>
                                <input type="text" value={data.name} onChange={e => setData('name', e.target.value)} required className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div className="flex gap-2 pt-2">
                                <button type="submit" disabled={processing} className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                    {processing ? t('common.creating') : t('contacts_page.seg_create')}
                                </button>
                                <button type="button" onClick={() => setShowCreate(false)} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
