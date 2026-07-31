import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, CheckCircle2, FileSpreadsheet, Search, Trash2, Upload, UserPlus, Users } from 'lucide-react';

function ContactRow({ contact }) {
    const name = [contact.first_name, contact.last_name].filter(Boolean).join(' ') || 'Unnamed contact';
    return (
        <div className="min-w-0">
            <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{name}</p>
            <p className="truncate text-xs text-neutral-500 dark:text-neutral-400">{contact.phone_e164 ?? contact.email ?? 'No phone or email'}</p>
        </div>
    );
}

function Pager({ page }) {
    if (!page?.links || page.last_page <= 1) return null;
    return (
        <div className="flex flex-wrap gap-1 border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
            {page.links.map((link, index) => (
                <Link
                    key={`${link.label}-${index}`}
                    href={link.url ?? '#'}
                    preserveScroll
                    preserveState
                    className={`rounded-md px-2.5 py-1 text-xs ${link.active ? 'bg-brand-600 text-white' : link.url ? 'border border-neutral-200 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800' : 'cursor-not-allowed text-neutral-300 dark:text-neutral-700'}`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    );
}

function OperationStatus({ operation }) {
    const tone = operation.status === 'completed'
        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'
        : operation.status === 'failed'
            ? 'bg-red-50 text-red-700 dark:bg-red-950/30 dark:text-red-300'
            : 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-300';
    const percent = operation.total ? Math.min(100, Math.round((operation.processed / operation.total) * 100)) : null;
    const valid = Math.max(0, Number(operation.processed || 0) - Number(operation.skipped || 0));

    return (
        <div className={`rounded-lg px-3 py-2 text-xs ${tone}`}>
            <div className="flex items-center justify-between gap-3">
                <span className="font-medium">{operation.type === 'csv_import' ? 'CSV import' : 'Add all contacts'} · {operation.status}</span>
                <span>{valid.toLocaleString()} valid · {Number(operation.skipped || 0).toLocaleString()} skipped</span>
            </div>
            {percent !== null && operation.status !== 'completed' && (
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-black/10">
                    <div className="h-full rounded-full bg-current transition-all" style={{ width: `${percent}%` }} />
                </div>
            )}
            {operation.error_message && <p className="mt-1">{operation.error_message}</p>}
        </div>
    );
}

export default function SegmentContacts({ segment, segmentContacts, availableContacts, availableCount, uploadedAudienceCount, filters, operations }) {
    const { props } = usePage();
    const [tab, setTab] = useState('existing');
    const [selected, setSelected] = useState([]);
    const [selectAllMatching, setSelectAllMatching] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const searchTimer = useRef(null);
    const csvForm = useForm({ file: null });
    const currentPageIds = useMemo(() => availableContacts.data.map(contact => contact.id), [availableContacts.data]);
    const currentPageSelected = currentPageIds.length > 0 && currentPageIds.every(id => selected.includes(id));
    const hasRunningOperation = operations.some(operation => ['queued', 'processing'].includes(operation.status));

    useEffect(() => () => clearTimeout(searchTimer.current), []);

    useEffect(() => {
        if (!hasRunningOperation) return undefined;
        const timer = window.setInterval(() => router.reload({ only: ['operations', 'segment', 'segmentContacts', 'availableContacts', 'availableCount', 'uploadedAudienceCount'] }), 3000);
        return () => window.clearInterval(timer);
    }, [hasRunningOperation]);

    const applySearch = (value) => {
        setSearch(value);
        setSelected([]);
        setSelectAllMatching(false);
        clearTimeout(searchTimer.current);
        searchTimer.current = setTimeout(() => {
            router.get(route('client.segments.contacts', segment.id), { search: value }, { preserveState: true, preserveScroll: true, replace: true });
        }, 350);
    };

    const toggleCurrentPage = () => {
        setSelectAllMatching(false);
        setSelected(previous => currentPageSelected
            ? previous.filter(id => !currentPageIds.includes(id))
            : [...new Set([...previous, ...currentPageIds])]);
    };

    const addExisting = () => {
        router.post(route('client.segments.contacts.attach', segment.id), {
            selection: selectAllMatching ? 'all' : 'selected',
            contact_ids: selectAllMatching ? [] : selected,
            search,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelected([]);
                setSelectAllMatching(false);
            },
        });
    };

    const uploadCsv = (event) => {
        event.preventDefault();
        csvForm.post(route('client.segments.contacts.import', segment.id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => csvForm.reset('file'),
        });
    };

    return (
        <ClientLayout title={`Add Contacts · ${segment.name}`}>
            <Head title={`Add Contacts · ${segment.name}`} />
            <div className="mx-auto max-w-6xl space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Link href={route('client.segments.index')} className="text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200"><ArrowLeft className="h-5 w-5" /></Link>
                        <div>
                            <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">Add Contacts to “{segment.name}”</h2>
                            <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                                Existing customers remain in the CRM. CSV uploads are stored as a separate campaign-only audience.
                            </p>
                        </div>
                    </div>
                    <div className="rounded-lg bg-neutral-100 px-3 py-2 text-sm text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <strong>{Number(segment.contact_count ?? segmentContacts.total).toLocaleString()}</strong> contacts in this list
                    </div>
                </div>

                {(props.flash?.success || props.errors?.file) && (
                    <div className={`rounded-lg px-4 py-3 text-sm ${props.errors?.file ? 'bg-red-50 text-red-700 dark:bg-red-950/30 dark:text-red-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'}`}>
                        {props.errors?.file ?? props.flash?.success}
                    </div>
                )}

                {operations.length > 0 && (
                    <div className="space-y-2">
                        {operations.map(operation => <OperationStatus key={operation.id} operation={operation} />)}
                    </div>
                )}

                {uploadedAudienceCount > 0 && (
                    <div className="rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-800 dark:border-violet-900 dark:bg-violet-950/30 dark:text-violet-200">
                        <strong>{uploadedAudienceCount.toLocaleString()} uploaded campaign recipients</strong>
                        <span className="ml-1">are available to campaigns through this contact list and are intentionally excluded from the main customer directory.</span>
                    </div>
                )}

                <div className="grid grid-cols-2 gap-2 rounded-xl border border-neutral-200 bg-white p-1.5 dark:border-neutral-700 dark:bg-neutral-900 sm:w-fit">
                    <button type="button" onClick={() => setTab('existing')} className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium ${tab === 'existing' ? 'bg-brand-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'}`}>
                        <Users className="h-4 w-4" /> Existing Contacts
                    </button>
                    <button type="button" onClick={() => setTab('csv')} className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium ${tab === 'csv' ? 'bg-brand-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'}`}>
                        <FileSpreadsheet className="h-4 w-4" /> Upload CSV
                    </button>
                </div>

                {tab === 'existing' && (
                    <div className="grid gap-5 lg:grid-cols-[1.4fr_1fr]">
                        <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                            <div className="space-y-3 border-b border-neutral-100 p-4 dark:border-neutral-800">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Choose existing contacts</h3>
                                        <p className="text-xs text-neutral-500">{availableCount.toLocaleString()} contacts available</p>
                                    </div>
                                    <button type="button" disabled={!selectAllMatching && selected.length === 0} onClick={addExisting} className="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40">
                                        <UserPlus className="h-4 w-4" />
                                        {selectAllMatching ? `Add all ${availableCount.toLocaleString()}` : `Add ${selected.length || ''} selected`}
                                    </button>
                                </div>
                                <div className="relative">
                                    <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                                    <input value={search} onChange={event => applySearch(event.target.value)} placeholder="Search by name, phone, or email…" className="w-full rounded-lg border border-neutral-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-neutral-600 dark:bg-neutral-800" />
                                </div>
                                <label className="flex cursor-pointer items-center gap-2 text-sm text-neutral-700 dark:text-neutral-200">
                                    <input type="checkbox" checked={currentPageSelected} onChange={toggleCurrentPage} className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500" />
                                    Select this page ({currentPageIds.length})
                                </label>
                                {(currentPageSelected || selectAllMatching) && availableCount > currentPageIds.length && (
                                    <button type="button" onClick={() => { setSelectAllMatching(!selectAllMatching); setSelected([]); }} className="w-full rounded-lg bg-brand-50 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-100 dark:bg-brand-950/30 dark:text-brand-300">
                                        {selectAllMatching
                                            ? <span className="inline-flex items-center gap-2"><CheckCircle2 className="h-4 w-4" /> All {availableCount.toLocaleString()} matching contacts selected. Undo</span>
                                            : `Select all ${availableCount.toLocaleString()} matching contacts`}
                                    </button>
                                )}
                            </div>
                            <div className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {availableContacts.data.map(contact => (
                                    <label key={contact.id} className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                                        <input type="checkbox" disabled={selectAllMatching} checked={selectAllMatching || selected.includes(contact.id)} onChange={() => setSelected(previous => previous.includes(contact.id) ? previous.filter(id => id !== contact.id) : [...previous, contact.id])} className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500" />
                                        <ContactRow contact={contact} />
                                    </label>
                                ))}
                                {availableContacts.data.length === 0 && <p className="px-4 py-10 text-center text-sm text-neutral-500">No matching contacts remain to add.</p>}
                            </div>
                            <Pager page={availableContacts} />
                        </section>

                        <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                            <div className="border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Already in this list</h3>
                                        <p className="text-xs text-neutral-500">{segmentContacts.total.toLocaleString()} existing customer{segmentContacts.total === 1 ? '' : 's'}</p>
                                    </div>
                                    {segmentContacts.total > 0 && (
                                        <button type="button" onClick={() => confirm('Remove all existing contacts from this list? Their customer records will not be deleted.') && router.delete(route('client.segments.contacts.detach-all', segment.id), { preserveScroll: true })} className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">
                                            <Trash2 className="h-3.5 w-3.5" /> Remove all
                                        </button>
                                    )}
                                </div>
                            </div>
                            <div className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {segmentContacts.data.map(contact => (
                                    <div key={contact.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                        <ContactRow contact={contact} />
                                        <button type="button" onClick={() => confirm('Remove this contact from the list?') && router.delete(route('client.segments.contacts.detach', [segment.id, contact.uuid]), { preserveScroll: true })} className="text-neutral-400 hover:text-red-500" title="Remove from list"><Trash2 className="h-4 w-4" /></button>
                                    </div>
                                ))}
                                {segmentContacts.data.length === 0 && <p className="px-4 py-10 text-center text-sm text-neutral-500">This contact list is empty.</p>}
                            </div>
                            <Pager page={segmentContacts} />
                        </section>
                    </div>
                )}

                {tab === 'csv' && (
                    <section className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
                        <div className="mx-auto max-w-2xl space-y-5">
                            <div className="rounded-xl border-2 border-dashed border-neutral-300 p-8 text-center dark:border-neutral-600">
                                <Upload className="mx-auto h-9 w-9 text-brand-600" />
                                <h3 className="mt-3 font-semibold text-neutral-900 dark:text-neutral-100">Import a large contact list</h3>
                                <p className="mt-1 text-sm text-neutral-500">CSV files are scanned and processed in 1,000-row chunks. The live result shows campaign-ready numbers and skipped rows. They are added only to this campaign audience, never to Contacts or the support customer directory.</p>
                                <form onSubmit={uploadCsv} className="mt-5 space-y-3">
                                    <input type="file" accept=".csv,text/csv" required onChange={event => csvForm.setData('file', event.target.files?.[0] ?? null)} className="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:font-medium file:text-brand-700" />
                                    <button disabled={csvForm.processing || !csvForm.data.file} className="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40">
                                        {csvForm.processing ? 'Uploading…' : 'Upload and start import'}
                                    </button>
                                </form>
                            </div>
                            <div className="rounded-lg bg-neutral-50 p-4 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                                <p className="font-medium text-neutral-900 dark:text-neutral-100">CSV format</p>
                                <p className="mt-1">Required: <code>phone_e164</code>. International formats such as <code>+96170123456</code>, <code>0096170123456</code>, and <code>96170123456</code> are normalized automatically. Optional: <code>first_name</code>, <code>last_name</code>, <code>email</code>, <code>country</code>, <code>language</code>, <code>opt_in_sms</code>.</p>
                                <p className="mt-2">Local-only numbers without a country code, malformed rows, and duplicates are skipped. If a phone already belongs to a real customer, that customer remains in the CRM.</p>
                                <a href={route('client.segments.contacts.sample-csv')} className="mt-3 inline-flex items-center gap-1.5 font-medium text-brand-700 hover:text-brand-800 dark:text-brand-300">
                                    <FileSpreadsheet className="h-4 w-4" /> Download sample CSV
                                </a>
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </ClientLayout>
    );
}
