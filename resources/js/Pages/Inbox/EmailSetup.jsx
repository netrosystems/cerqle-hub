import { Head, Link, router, useForm } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { Mail, RefreshCw, Trash2, ShieldCheck, Server, AlertTriangle, AtSign } from 'lucide-react';

function AccountCard({ account }) {
    return <div className="flex items-start gap-3 rounded-xl border border-neutral-200 bg-white p-4 dark:border-neutral-700 dark:bg-neutral-900">
        <div className="rounded-lg bg-brand-50 p-2 text-brand-600 dark:bg-brand-950/40"><Mail className="h-5 w-5" /></div>
        <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><p className="font-semibold text-neutral-900 dark:text-white">{account.display_name}</p><span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${account.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>{account.status}</span></div><p className="truncate text-sm text-neutral-500">{account.email} · {account.provider === 'microsoft_365' ? 'Microsoft 365' : account.provider === 'gmail' ? 'Google Gmail' : 'IMAP / SMTP'}</p>{account.last_synced_at && <p className="mt-1 text-xs text-neutral-400">Last sync: {new Date(account.last_synced_at).toLocaleString()}</p>}{account.last_sync_error && <p className="mt-1 text-xs text-red-600">{account.last_sync_error}</p>}</div>
        <button onClick={() => router.post(route('client.inbox.email.sync', account.id))} className="rounded-lg p-2 text-neutral-500 hover:bg-neutral-100" title="Sync now"><RefreshCw className="h-4 w-4" /></button>
        <button onClick={() => confirm('Disconnect this mailbox? Existing conversations will be kept.') && router.delete(route('client.inbox.email.destroy', account.id))} className="rounded-lg p-2 text-red-500 hover:bg-red-50" title="Disconnect"><Trash2 className="h-4 w-4" /></button>
    </div>;
}

export default function EmailSetup({ accounts, googleEnabled, googleCallbackUrl, microsoftEnabled, microsoftCallbackUrl, imapExtensionAvailable }) {
    const form = useForm({ email: '', display_name: '', imap_host: '', imap_port: 993, imap_encryption: 'ssl', smtp_host: '', smtp_port: 465, smtp_encryption: 'ssl', username: '', password: '', verify_tls: true });
    const submit = e => { e.preventDefault(); form.post(route('client.inbox.email.generic.store'), { preserveScroll: true, onSuccess: () => form.reset() }); };
    return <ClientLayout><Head title="Email Setup" /><div className="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold text-neutral-900 dark:text-white">Email Setup</h1><p className="text-sm text-neutral-500">Connect mailboxes to the Master Email Inbox.</p></div><Link href={route('client.inbox.email-inbox')} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Open Master Email Inbox</Link></div>
        {accounts.length > 0 && <section className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div><h2 className="text-base font-semibold text-neutral-900 dark:text-white">Connected mailboxes</h2><p className="text-xs text-neutral-500">All mailboxes sync independently into one Master Email Inbox.</p></div>
                <span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">{accounts.length} connected</span>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">{accounts.map(a => <AccountCard key={a.id} account={a} />)}</div>
        </section>}
        <div>
            <h2 className="text-base font-semibold text-neutral-900 dark:text-white">{accounts.length ? 'Add another mailbox' : 'Connect your first mailbox'}</h2>
            <p className="mt-1 text-xs text-neutral-500">Choose any provider below. You can connect multiple accounts from the same or different providers.</p>
        </div>
        <div className="grid gap-5 lg:grid-cols-2">
            <section className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><div className="mb-4 flex items-center gap-3"><div className="rounded-xl bg-red-50 p-2 text-red-600"><AtSign className="h-5 w-5" /></div><div><h2 className="font-semibold">Google Gmail OAuth</h2><p className="text-xs text-neutral-500">Recommended for Gmail and Google Workspace mail.</p></div></div><p className="mb-4 text-sm text-neutral-600 dark:text-neutral-300">Uses the Gmail API with per-user consent. Passwords are never collected and access can be revoked from Google.</p><a href={googleEnabled ? route('client.inbox.email.google.connect') : '#'} className={`block rounded-lg px-4 py-2.5 text-center text-sm font-semibold ${googleEnabled ? 'bg-[#4285F4] text-white' : 'cursor-not-allowed bg-neutral-100 text-neutral-400'}`}>Add Gmail mailbox</a>{!googleEnabled && <p className="mt-3 text-xs text-amber-600">A Super Admin must configure Google Gmail OAuth under Integrations first.</p>}<div className="mt-4 rounded-lg bg-neutral-50 p-3 text-xs text-neutral-500 dark:bg-neutral-800">Redirect URI: <code className="break-all">{googleCallbackUrl}</code></div></section>
            <section className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900"><div className="mb-4 flex items-center gap-3"><div className="rounded-xl bg-blue-50 p-2 text-blue-600"><ShieldCheck className="h-5 w-5" /></div><div><h2 className="font-semibold">Microsoft 365 OAuth</h2><p className="text-xs text-neutral-500">Recommended for Outlook and Microsoft 365.</p></div></div><p className="mb-4 text-sm text-neutral-600 dark:text-neutral-300">Uses Microsoft Graph. Passwords are never shared and access can be revoked from Microsoft.</p><a href={microsoftEnabled ? route('client.inbox.email.microsoft.connect') : '#'} className={`block rounded-lg px-4 py-2.5 text-center text-sm font-semibold ${microsoftEnabled ? 'bg-[#2F2F2F] text-white' : 'cursor-not-allowed bg-neutral-100 text-neutral-400'}`}>Add Microsoft mailbox</a>{!microsoftEnabled && <p className="mt-3 text-xs text-amber-600">A Super Admin must configure Microsoft 365 OAuth under Integrations first.</p>}<div className="mt-4 rounded-lg bg-neutral-50 p-3 text-xs text-neutral-500 dark:bg-neutral-800">Redirect URI: <code className="break-all">{microsoftCallbackUrl}</code></div></section>
            <section className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-700 dark:bg-neutral-900 lg:col-span-2 lg:mx-auto lg:w-full lg:max-w-3xl">
                <div className="mb-4 flex items-center gap-3">
                    <div className="rounded-xl bg-violet-50 p-2 text-violet-600"><Server className="h-5 w-5" /></div>
                    <div><h2 className="font-semibold">Generic IMAP / SMTP</h2><p className="text-xs text-neutral-500">Connect a cPanel or custom mailbox.</p></div>
                </div>
                {!imapExtensionAvailable && <div className="mb-4 flex gap-2 rounded-lg bg-amber-50 p-3 text-xs text-amber-700"><AlertTriangle className="h-4 w-4 shrink-0" /><span>PHP IMAP is not installed on this server. Ask the server administrator to install and enable the PHP IMAP extension before connecting a mailbox.</span></div>}
                <form onSubmit={submit} className="space-y-4">
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
                    <div className="flex justify-end">
                        <button disabled={form.processing || !imapExtensionAvailable} className="w-full rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50 sm:w-auto">{form.processing ? 'Testing IMAP and SMTP…' : 'Test and connect'}</button>
                    </div>
                </form>
            </section>
        </div>
    </div></ClientLayout>;
}
function Input({ label, value, onChange, type = 'text' }) { return <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}<input required={label !== 'Display name'} type={type} value={value} onChange={e => onChange(e.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800" /></label>; }
function Select({ label, value, onChange }) { return <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">{label}<select value={value} onChange={e => onChange(e.target.value)} className="mt-1 w-full rounded-lg border-neutral-300 text-sm dark:border-neutral-700 dark:bg-neutral-800"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none">None (not recommended)</option></select></label>; }
