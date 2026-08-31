import { Head, router, useForm } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Modal from '@/Components/ui/Modal';
import { Mail, RefreshCw, Trash2, ShieldCheck, Server, AlertTriangle, AtSign } from 'lucide-react';
import { useState } from 'react';

function AccountCard({ account }) {
    const [syncing, setSyncing] = useState(false);
    const sync = () => {
        if (syncing) return;
        setSyncing(true);
        router.post(route('client.inbox.email.sync', account.id), {}, {
            preserveScroll: true,
            onFinish: () => setSyncing(false),
        });
    };
    return <div className="flex items-start gap-3 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <div className="rounded-lg bg-brand-50 p-2 text-brand-600 dark:bg-brand-950/40"><Mail className="h-5 w-5" /></div>
        <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><p className="font-semibold text-neutral-900 dark:text-white">{account.display_name}</p><span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${account.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>{account.status}</span></div><p className="truncate text-sm text-neutral-500">{account.email} · {account.provider === 'microsoft_365' ? 'Microsoft 365' : account.provider === 'gmail' ? 'Google Gmail' : 'IMAP / SMTP'}</p>{account.last_synced_at && <p className="mt-1 text-xs text-neutral-400">Last sync: {new Date(account.last_synced_at).toLocaleString()}</p>}{account.last_sync_error && <p className="mt-1 text-xs text-red-600">{account.last_sync_error}</p>}</div>
        <button onClick={sync} disabled={syncing} className="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 disabled:opacity-50" title={syncing ? 'Queueing sync…' : 'Sync now'}><RefreshCw className={`h-4 w-4 ${syncing ? 'animate-spin' : ''}`} /></button>
        <button onClick={() => confirm('Disconnect this mailbox? Existing conversations will be kept.') && router.delete(route('client.inbox.email.destroy', account.id))} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Disconnect"><Trash2 className="h-4 w-4" /></button>
    </div>;
}

function ProviderCard({ icon: Icon, iconClass, iconWrapClass, title, count, description, action, disabled = false, disabledMessage }) {
    return (
        <section className="flex min-h-[250px] flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-700 dark:bg-neutral-900">
            <div className="flex items-center gap-3 border-b border-neutral-100 px-5 py-4 dark:border-neutral-800">
                <div className={`rounded-xl p-2 ${iconWrapClass}`}><Icon className={`h-4 w-4 ${iconClass}`} /></div>
                <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{title}</h2>
                <span className={`ml-auto rounded-full px-2 py-0.5 text-xs font-medium ${count > 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'}`}>
                    {count} connected
                </span>
            </div>
            <div className="flex flex-1 flex-col items-center justify-center px-6 py-7 text-center">
                <div className={`mb-4 flex h-12 w-12 items-center justify-center rounded-2xl ${iconWrapClass}`}>
                    <Icon className={`h-6 w-6 ${iconClass}`} />
                </div>
                <p className="max-w-xs text-sm leading-6 text-neutral-500 dark:text-neutral-400">{description}</p>
                <div className="mt-5">
                    {action}
                    {disabled && disabledMessage && <p className="mt-2 max-w-xs text-xs text-amber-600 dark:text-amber-400">{disabledMessage}</p>}
                </div>
            </div>
        </section>
    );
}

export default function EmailSetup({ accounts, googleEnabled, microsoftEnabled, imapExtensionAvailable }) {
    const [showGenericSetup, setShowGenericSetup] = useState(false);
    const form = useForm({ email: '', display_name: '', imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl', username: '', password: '', verify_tls: true });
    const providerCount = provider => accounts.filter(account => account.provider === provider).length;
    const submit = e => {
        e.preventDefault();
        form.post(route('client.inbox.email.generic.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setShowGenericSetup(false);
            },
        });
    };
    return <ClientLayout title="Email Setup"><Head title="Email Setup" /><div className="space-y-6">
        <div><h1 className="text-xl font-bold text-neutral-900 dark:text-white">Email Setup</h1><p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">Connect mailboxes to the Master Email Inbox.</p></div>
        {accounts.length > 0 && <section className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div><h2 className="text-base font-semibold text-neutral-900 dark:text-white">Connected mailboxes</h2><p className="text-xs text-neutral-500">All mailboxes sync independently into one Master Email Inbox.</p></div>
                <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{accounts.length} connected</span>
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{accounts.map(a => <AccountCard key={a.id} account={a} />)}</div>
        </section>}
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <ProviderCard
                icon={AtSign}
                iconClass="text-red-600 dark:text-red-400"
                iconWrapClass="bg-red-50 dark:bg-red-950/30"
                title="Google Gmail OAuth"
                count={providerCount('gmail')}
                description="Connect Gmail or Google Workspace securely without sharing a password."
                disabled={!googleEnabled}
                disabledMessage="Gmail OAuth must be configured by a Super Admin first."
                action={<a href={googleEnabled ? route('client.inbox.email.google.connect') : undefined} aria-disabled={!googleEnabled} className={`inline-flex items-center rounded-lg px-4 py-2 text-xs font-semibold transition ${googleEnabled ? 'bg-[#4285F4] text-white hover:bg-[#3578e5]' : 'cursor-not-allowed bg-neutral-100 text-neutral-400 dark:bg-neutral-800'}`}>Connect Gmail</a>}
            />
            <ProviderCard
                icon={ShieldCheck}
                iconClass="text-blue-600 dark:text-blue-400"
                iconWrapClass="bg-blue-50 dark:bg-blue-950/30"
                title="Microsoft 365 OAuth"
                count={providerCount('microsoft_365')}
                description="Connect Outlook or Microsoft 365 securely through Microsoft Graph."
                disabled={!microsoftEnabled}
                disabledMessage="Microsoft OAuth must be configured by a Super Admin first."
                action={<a href={microsoftEnabled ? route('client.inbox.email.microsoft.connect') : undefined} aria-disabled={!microsoftEnabled} className={`inline-flex items-center rounded-lg px-4 py-2 text-xs font-semibold transition ${microsoftEnabled ? 'bg-[#2F2F2F] text-white hover:bg-black' : 'cursor-not-allowed bg-neutral-100 text-neutral-400 dark:bg-neutral-800'}`}>Connect Microsoft</a>}
            />
            <ProviderCard
                icon={Server}
                iconClass="text-violet-600 dark:text-violet-400"
                iconWrapClass="bg-violet-50 dark:bg-violet-950/30"
                title="Generic IMAP / SMTP"
                count={providerCount('imap_smtp')}
                description="Connect a cPanel or custom mailbox using its incoming and outgoing server details."
                disabled={!imapExtensionAvailable}
                disabledMessage="PHP IMAP must be installed on the server first."
                action={<button type="button" onClick={() => setShowGenericSetup(true)} disabled={!imapExtensionAvailable} className="rounded-lg bg-brand-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:text-neutral-400 dark:disabled:bg-neutral-800">Connect custom mailbox</button>}
            />
        </div>

        <Modal show={showGenericSetup} onClose={() => !form.processing && setShowGenericSetup(false)} maxWidth="3xl">
            <form onSubmit={submit}>
                <Modal.Header title="Connect a custom mailbox" onClose={() => setShowGenericSetup(false)} />
                <Modal.Body className="max-h-[70vh] space-y-4 overflow-y-auto">
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">Enter the IMAP and SMTP settings supplied by your email hosting provider.</p>
                    {!imapExtensionAvailable && <div className="flex gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-700 dark:bg-amber-950/30 dark:text-amber-300"><AlertTriangle className="h-4 w-4 shrink-0" /><span>PHP IMAP is not installed on this server. Ask the server administrator to install and enable the PHP IMAP extension before connecting a mailbox.</span></div>}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Mailbox email" value={form.data.email} onChange={v => form.setData('email', v)} type="email" />
                        <Input label="Display name" value={form.data.display_name} onChange={v => form.setData('display_name', v)} />
                    </div>
                    <div className="rounded-xl border border-neutral-200 p-3 dark:border-neutral-700">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">Incoming mail</p>
                        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_90px_140px]">
                            <Input label="IMAP host" value={form.data.imap_host} onChange={v => form.setData('imap_host', v)} />
                            <Input label="Port" value={form.data.imap_port} onChange={v => form.setData('imap_port', Number(v))} type="number" />
                            <Select label="Security" value={form.data.imap_encryption} onChange={v => form.setData('imap_encryption', v)} />
                        </div>
                    </div>
                    <div className="rounded-xl border border-neutral-200 p-3 dark:border-neutral-700">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-400">Outgoing mail</p>
                        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_90px_140px]">
                            <Input label="SMTP host" value={form.data.smtp_host} onChange={v => form.setData('smtp_host', v)} />
                            <Input label="Port" value={form.data.smtp_port} onChange={v => form.setData('smtp_port', Number(v))} type="number" />
                            <Select label="Security" value={form.data.smtp_encryption} onChange={v => form.setData('smtp_encryption', v)} />
                        </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Username" value={form.data.username} onChange={v => form.setData('username', v)} />
                        <Input label="Password / app password" value={form.data.password} onChange={v => form.setData('password', v)} type="password" />
                    </div>
                    {Object.keys(form.errors).length > 0 && <p className="rounded-lg bg-red-50 p-3 text-xs text-red-700">{Object.values(form.errors)[0]}</p>}
                </Modal.Body>
                <Modal.Footer>
                    <button type="button" onClick={() => setShowGenericSetup(false)} disabled={form.processing} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-semibold text-neutral-700 transition hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800">Cancel</button>
                    <button disabled={form.processing || !imapExtensionAvailable} className="rounded-lg bg-brand-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{form.processing ? 'Testing IMAP and SMTP…' : 'Test and connect'}</button>
                </Modal.Footer>
            </form>
        </Modal>
    </div></ClientLayout>;
}
function Input({ label, value, onChange, type = 'text' }) { return <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}<input required={label !== 'Display name'} type={type} value={value} onChange={e => onChange(e.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" /></label>; }
function Select({ label, value, onChange }) { return <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}<select value={value} onChange={e => onChange(e.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none">None (not recommended)</option></select></label>; }
