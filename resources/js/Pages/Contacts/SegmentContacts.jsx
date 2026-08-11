import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { isLiveOperation } from '@/lib/contactListOperations';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, CheckCircle2, FileSpreadsheet, Search, Trash2, Upload, UserPlus } from 'lucide-react';

function ContactRow({ contact }) {
    const name = [contact.first_name, contact.last_name].filter(Boolean).join(' ') || 'Unnamed contact';
    return (
        <div className="min-w-0">
            <p className="truncate text-sm font-medium text-neutral-900 dark:text-neutral-100">{name}</p>
            <p className="truncate text-xs text-neutral-500 dark:text-neutral-400">{contact.phone_e164 ?? contact.email ?? 'No phone or email'}</p>
        </div>
    );
}

function SourceChip({ source }) {
    if (source === 'uploaded') {
        return (
            <span className="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-medium text-violet-700 dark:bg-violet-950/40 dark:text-violet-300">
                Uploaded
            </span>
        );
    }
    return (
        <span className="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
            Existing customer
        </span>
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

function OperationStatus({ operation, confirmImport }) {
    const added = Number(operation.added || 0);
    const validation = operation.options?.validation || {};
    const existingCustomerSkip = Number(operation.skipped_existing_customer || 0);
    const invalidPhone = Number(operation.skipped_invalid_phone || 0);
    const malformedRow = Number(operation.skipped_malformed_row || 0);
    const duplicateInFile = Number(operation.skipped_duplicate_in_file || 0);
    // `skipped` is the sum of all reject buckets; this fallback handles old
    // records that were imported before the breakdown existed.
    const accounted = existingCustomerSkip + invalidPhone + malformedRow + duplicateInFile;
    const unknown = Math.max(0, Number(operation.skipped || 0) - accounted);
    const totalRejected = Number(operation.skipped || 0);
    const percent = operation.total ? Math.min(100, Math.round((operation.processed / operation.total) * 100)) : null;

    const tone = operation.status === 'completed'
        ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'
        : operation.status === 'failed'
            ? 'bg-red-50 text-red-700 dark:bg-red-950/30 dark:text-red-300'
            : 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-300';

    const isValidation = operation.type === 'csv_validation';
    const showBreakdown = (operation.type === 'csv_import' || isValidation) && totalRejected > 0;

    return (
        <div className={`rounded-lg px-3 py-2 text-xs ${tone}`}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="font-medium">{isValidation ? 'CSV validation' : operation.type === 'csv_import' ? 'CSV import' : 'Add all contacts'} · {operation.status}</span>
                <span className="flex flex-wrap gap-3">
                    <span><strong className="font-semibold">{added.toLocaleString()}</strong> {isValidation ? 'ready to import' : 'added'}</span>
                    {totalRejected > 0 && (
                        <span><strong className="font-semibold">{totalRejected.toLocaleString()}</strong> rejected</span>
                    )}
                </span>
            </div>
            {showBreakdown && (
                <p className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-[11px] opacity-90">
                    {Number(validation.missing_phone || 0) > 0 && (
                        <span><strong className="font-semibold">{Number(validation.missing_phone).toLocaleString()}</strong> blank phone</span>
                    )}
                    {Number(validation.missing_country || 0) > 0 && (
                        <span><strong className="font-semibold">{Number(validation.missing_country).toLocaleString()}</strong> needs country</span>
                    )}
                    {Number(validation.invalid_country || 0) > 0 && (
                        <span><strong className="font-semibold">{Number(validation.invalid_country).toLocaleString()}</strong> invalid country</span>
                    )}
                    {(Number(validation.invalid_phone || 0) > 0 || (!isValidation && invalidPhone > 0)) && (
                        <span><strong className="font-semibold">{(Number(validation.invalid_phone || 0) || invalidPhone).toLocaleString()}</strong> invalid phone</span>
                    )}
                    {existingCustomerSkip > 0 && (
                        <span><strong className="font-semibold">{existingCustomerSkip.toLocaleString()}</strong> matched an existing customer</span>
                    )}
                    {malformedRow > 0 && (
                        <span><strong className="font-semibold">{malformedRow.toLocaleString()}</strong> malformed row</span>
                    )}
                    {duplicateInFile > 0 && (
                        <span><strong className="font-semibold">{duplicateInFile.toLocaleString()}</strong> duplicate in file</span>
                    )}
                    {unknown > 0 && (
                        <span><strong className="font-semibold">{unknown.toLocaleString()}</strong> other</span>
                    )}
                </p>
            )}
            {percent !== null && operation.status !== 'completed' && (
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-black/10">
                    <div className="h-full rounded-full bg-current transition-all" style={{ width: `${percent}%` }} />
                </div>
            )}
            {operation.error_message && <p className="mt-1">{operation.error_message}</p>}
            {isValidation && operation.status === 'completed' && (
                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-current/15 pt-2">
                    <span className="text-[11px]">No contacts have been added yet.</span>
                    <button type="button" disabled={added === 0} onClick={() => confirmImport(operation)} className="rounded-md bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40">
                        Import {added.toLocaleString()} validated contacts
                    </button>
                </div>
            )}
        </div>
    );
}

// A background operation is "interesting" only while it has actually
// progressed past `queued`. Showing every queued record forever means
// crashed jobs (no worker, killed process, etc.) haunt the page.
// (Logic lives in `@/lib/contactListOperations` so it can be unit-tested.)

function ExistingCustomerPicker({ availableContacts, availableCount, search, applySearch, selected, setSelected, selectAllMatching, setSelectAllMatching, currentPageIds, currentPageSelected, toggleCurrentPage, addExisting }) {
    return (
        <section className="flex h-full flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
            <div className="space-y-3 border-b border-neutral-100 p-4 dark:border-neutral-800">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Choose existing customers</h3>
                        <p className="text-xs text-neutral-500">{availableCount.toLocaleString()} customers available to add</p>
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
            <div className="max-h-[420px] flex-1 divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
                {availableContacts.data.map(contact => (
                    <label key={contact.id} className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <input type="checkbox" disabled={selectAllMatching} checked={selectAllMatching || selected.includes(contact.id)} onChange={() => setSelected(previous => previous.includes(contact.id) ? previous.filter(id => id !== contact.id) : [...previous, contact.id])} className="rounded border-neutral-300 text-brand-600 focus:ring-brand-500" />
                        <ContactRow contact={contact} />
                    </label>
                ))}
                {availableContacts.data.length === 0 && <p className="px-4 py-10 text-center text-sm text-neutral-500">No matching customers remain to add.</p>}
            </div>
            <Pager page={availableContacts} />
        </section>
    );
}

function CsvUploader({ csvForm, uploadCsv, importLimits }) {
    const [fileError, setFileError] = useState('');
    const maxFileMb = Number(importLimits?.maxFileMb || 20);
    const maxRows = Number(importLimits?.maxRowsPerFile || 250000);
    const maxFileBytes = maxFileMb * 1024 * 1024;

    const chooseFile = (event) => {
        const file = event.target.files?.[0] ?? null;
        if (file && file.size > maxFileBytes) {
            setFileError(`This file is ${Math.ceil(file.size / 1024 / 1024)} MB. The maximum is ${maxFileMb} MB. Split it into smaller CSV files and upload them one at a time.`);
            csvForm.setData('file', null);
            event.target.value = '';
            return;
        }
        setFileError('');
        csvForm.setData('file', file);
    };

    return (
        <section className="flex h-full flex-col rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-700 dark:bg-neutral-900">
            <div className="space-y-5">
                <div className="rounded-xl border-2 border-dashed border-neutral-300 p-6 text-center dark:border-neutral-600">
                    <Upload className="mx-auto h-9 w-9 text-brand-600" />
                    <h3 className="mt-3 font-semibold text-neutral-900 dark:text-neutral-100">Upload a CSV of recipients</h3>
                    <p className="mt-1 text-sm text-neutral-500">Your file is scanned first in 1,000-row chunks. Nothing is added until you review the accepted and rejected counts, then confirm the import.</p>
                    <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-left text-xs text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                        <strong>Per-file limit: {maxFileMb} MB or {maxRows.toLocaleString()} contacts.</strong>
                        <span className="mt-1 block">For 1,000,000 contacts, split the audience into at least 4 CSV files and upload them to this same Contact List one at a time.</span>
                    </div>
                    <form onSubmit={uploadCsv} className="mt-5 space-y-3">
                        <input type="file" accept=".csv,text/csv" required onChange={chooseFile} className="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-4 file:py-2 file:font-medium file:text-brand-700" />
                        {fileError && <p className="rounded-lg bg-red-50 px-3 py-2 text-left text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-300">{fileError}</p>}
                        <label className="block text-left text-sm font-medium text-neutral-700 dark:text-neutral-200">
                            Default country <span className="font-normal text-neutral-500">(only for national numbers without country code)</span>
                            <input value={csvForm.data.default_country} onChange={event => csvForm.setData('default_country', event.target.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 2))} placeholder="e.g. LB" maxLength={2} className="mt-1 block w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm uppercase dark:border-neutral-600 dark:bg-neutral-800" />
                        </label>
                        <button disabled={csvForm.processing || !csvForm.data.file} className="rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-40">
                            {csvForm.processing ? 'Uploading…' : 'Upload and validate'}
                        </button>
                    </form>
                </div>
                <div className="rounded-lg bg-neutral-50 p-4 text-sm text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
                    <p className="font-medium text-neutral-900 dark:text-neutral-100">CSV format</p>
                    <p className="mt-1">Required: a <code>Phone</code>, <code>Mobile</code>, <code>Phone Number</code>, or <code>phone_e164</code> column. International formats such as <code>+96170123456</code>, <code>0096170123456</code>, and <code>96170123456</code> are normalized automatically. Optional: <code>first_name</code>, <code>last_name</code>, <code>email</code>, <code>country</code>, <code>language</code>, <code>opt_in_sms</code>.</p>
                    <p className="mt-2">A national number needs either its own two-letter <code>country</code> value or the Default country above. Blank phones, malformed/invalid numbers, invalid country codes, duplicate rows, and numbers already belonging to CRM customers are counted separately before import.</p>
                    <a href={route('client.segments.contacts.sample-csv')} className="mt-3 inline-flex items-center gap-1.5 font-medium text-brand-700 hover:text-brand-800 dark:text-brand-300">
                        <FileSpreadsheet className="h-4 w-4" /> Download sample CSV
                    </a>
                </div>
            </div>
        </section>
    );
}

export default function SegmentContacts({ segment, listContacts, existingContactsCount, uploadedContactsCount, availableContacts, availableCount, filters, operations, importLimits }) {
    const { props } = usePage();
    const [selected, setSelected] = useState([]);
    const [selectAllMatching, setSelectAllMatching] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [confirmingClearAll, setConfirmingClearAll] = useState(false);
    const [clearAllPhrase, setClearAllPhrase] = useState('');
    const searchTimer = useRef(null);
    const csvForm = useForm({ file: null, default_country: '' });
    const currentPageIds = useMemo(() => availableContacts.data.map(contact => contact.id), [availableContacts.data]);
    const currentPageSelected = currentPageIds.length > 0 && currentPageIds.every(id => selected.includes(id));
    const liveOperations = operations.filter(operation => isLiveOperation(operation));
    const hasRunningOperation = liveOperations.some(operation => ['queued', 'processing'].includes(operation.status));
    // Use the live counts from the page payload. `segment.contact_count` is a
    // denormalised counter that can lag behind reality (especially right after
    // attach/detach) so prefer the freshly-computed values.
    const totalInList = Number(existingContactsCount) + Number(uploadedContactsCount);

    useEffect(() => () => clearTimeout(searchTimer.current), []);

    useEffect(() => {
        if (!hasRunningOperation) return undefined;
        const timer = window.setInterval(() => router.reload({ only: ['operations', 'segment', 'listContacts', 'existingContactsCount', 'uploadedContactsCount', 'availableContacts', 'availableCount'] }), 3000);
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

    const confirmImport = (operation) => {
        router.post(route('client.segments.contacts.import.confirm', [segment.id, operation.id]), {}, { preserveScroll: true });
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
                                Pick existing customers or upload a CSV. Uploaded rows are saved to this list only — they are never added to your main customer directory.
                            </p>
                        </div>
                    </div>
                    <div className="rounded-lg bg-neutral-100 px-3 py-2 text-sm text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200">
                        <strong>{totalInList.toLocaleString()}</strong> contacts in this list
                        <span className="ml-2 text-xs text-neutral-500 dark:text-neutral-400">
                            ({Number(existingContactsCount).toLocaleString()} existing · {Number(uploadedContactsCount).toLocaleString()} uploaded)
                        </span>
                    </div>
                </div>

                {(props.flash?.success || props.errors?.file) && (
                    <div className={`rounded-lg px-4 py-3 text-sm ${props.errors?.file ? 'bg-red-50 text-red-700 dark:bg-red-950/30 dark:text-red-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'}`}>
                        {props.errors?.file ?? props.flash?.success}
                    </div>
                )}

                {liveOperations.length > 0 && (
                    <div className="space-y-2">
                        {liveOperations.map(operation => <OperationStatus key={operation.id} operation={operation} confirmImport={confirmImport} />)}
                    </div>
                )}

                <div className="grid gap-5 lg:grid-cols-2">
                    <ExistingCustomerPicker
                        availableContacts={availableContacts}
                        availableCount={availableCount}
                        search={search}
                        applySearch={applySearch}
                        selected={selected}
                        setSelected={setSelected}
                        selectAllMatching={selectAllMatching}
                        setSelectAllMatching={setSelectAllMatching}
                        currentPageIds={currentPageIds}
                        currentPageSelected={currentPageSelected}
                        toggleCurrentPage={toggleCurrentPage}
                        addExisting={addExisting}
                    />
                    <CsvUploader
                        csvForm={csvForm}
                        uploadCsv={uploadCsv}
                        importLimits={importLimits}
                    />
                </div>

                <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <div>
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">Already in this list</h3>
                            <p className="text-xs text-neutral-500">
                                {Number(existingContactsCount).toLocaleString()} existing customer{existingContactsCount === 1 ? '' : 's'} · {Number(uploadedContactsCount).toLocaleString()} uploaded recipient{uploadedContactsCount === 1 ? '' : 's'}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {existingContactsCount > 0 && (
                                <button type="button" onClick={() => confirm('Remove all existing customers from this list? Their CRM records will not be deleted; only uploaded recipients remain.') && router.delete(route('client.segments.contacts.detach-all', segment.id), { preserveScroll: true })} className="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                    <Trash2 className="h-3.5 w-3.5" /> Remove existing
                                </button>
                            )}
                            {totalInList > 0 && (
                                <button type="button" onClick={() => { setClearAllPhrase(''); setConfirmingClearAll(true); }} className="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-2.5 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-900/60 dark:text-red-300 dark:hover:bg-red-950/30">
                                    <Trash2 className="h-3.5 w-3.5" /> Remove all
                                </button>
                            )}
                        </div>
                    </div>
                    <div className="max-h-[420px] divide-y divide-neutral-100 overflow-y-auto dark:divide-neutral-800">
                        {listContacts.data.map(contact => (
                            <div key={contact.id} className="flex items-center gap-3 px-4 py-3">
                                <SourceChip source={contact.source} />
                                <div className="min-w-0 flex-1">
                                    <ContactRow contact={contact} />
                                </div>
                                <button type="button" onClick={() => confirm('Remove this contact from the list?') && router.delete(route('client.segments.contacts.detach', [segment.id, contact.uuid]), { preserveScroll: true })} className="text-neutral-400 hover:text-red-500" title="Remove from list"><Trash2 className="h-4 w-4" /></button>
                            </div>
                        ))}
                        {listContacts.data.length === 0 && (
                            <p className="px-4 py-10 text-center text-sm text-neutral-500">This contact list is empty. Add existing customers or upload a CSV to get started.</p>
                        )}
                    </div>
                    <Pager page={listContacts} />
                </section>
            </div>

            {confirmingClearAll && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Remove every contact from this list?</h3>
                        <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-300">
                            <strong className="font-semibold">{totalInList.toLocaleString()}</strong> contact(s) will be taken off this contact list.
                            No contact records are deleted — CRM customers and uploaded recipients both stay in the system; they just lose membership in this list.
                        </p>
                        <p className="mt-3 text-sm text-neutral-600 dark:text-neutral-300">
                            Type <code className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">REMOVE ALL</code> to confirm.
                        </p>
                        <input
                            autoFocus
                            value={clearAllPhrase}
                            onChange={event => setClearAllPhrase(event.target.value)}
                            className="mt-3 w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-600 dark:bg-neutral-800"
                        />
                        <div className="mt-5 flex justify-end gap-2">
                            <button type="button" onClick={() => { setConfirmingClearAll(false); setClearAllPhrase(''); }} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-200 dark:hover:bg-neutral-800">
                                Cancel
                            </button>
                            <button type="button" disabled={clearAllPhrase !== 'REMOVE ALL'} onClick={() => { setConfirmingClearAll(false); setClearAllPhrase(''); router.delete(route('client.segments.contacts.clear', segment.id), { preserveScroll: true }); }} className="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-40">
                                Remove all
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
