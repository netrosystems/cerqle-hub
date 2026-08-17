import { Head, Link, router, usePage } from '@inertiajs/react';
import InboxLayout from '@/Layouts/InboxLayout';
import {
    Archive, ArrowLeft, CheckCircle2, ChevronLeft, ChevronRight, Circle,
    Inbox, Mail, MailOpen, RefreshCw, Search, Send, Settings2,
} from 'lucide-react';
import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';

const FOLDERS = [
    { key: 'inbox', label: 'Inbox', icon: Inbox },
    { key: 'unread', label: 'Unread', icon: MailOpen },
    { key: 'sent', label: 'Sent', icon: Send },
    { key: 'resolved', label: 'Resolved', icon: CheckCircle2 },
    { key: 'all', label: 'All mail', icon: Archive },
];

const PROVIDER_LABELS = {
    gmail: 'Gmail',
    microsoft_365: 'Microsoft 365',
    imap_smtp: 'IMAP / SMTP',
};

function contactName(conversation) {
    const contact = conversation?.contact;
    const name = [contact?.first_name, contact?.last_name].filter(Boolean).join(' ').trim();
    return name || contact?.email || 'Unknown sender';
}

function subjectOf(conversation) {
    return safeText(conversation?.latest_inbound_message?.payload?.subject)
        || safeText(conversation?.last_message?.payload?.subject)
        || '(no subject)';
}

function safeText(value, fallback = '') {
    if (typeof value === 'string' || typeof value === 'number') return String(value);
    return fallback;
}

function formatMailTime(value, timezone) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const options = {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() !== new Date().getFullYear() ? { year: 'numeric' } : {}),
    };
    try {
        return new Intl.DateTimeFormat(undefined, { ...options, timeZone: timezone }).format(date);
    } catch {
        return new Intl.DateTimeFormat(undefined, options).format(date);
    }
}

function formatMessageTime(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
}

function FolderNav({ filters, counts, onFolder }) {
    return <div className="space-y-1 p-3">
        <p className="px-3 pb-2 text-[11px] font-bold uppercase tracking-[0.16em] text-neutral-400">Folders</p>
        {FOLDERS.map(({ key, label, icon: Icon }) => <button
            key={key}
            type="button"
            onClick={() => onFolder(key)}
            className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition ${filters.folder === key ? 'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-950/40 dark:text-brand-300' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'}`}
        >
            <Icon className="h-4 w-4" />
            <span className="flex-1 text-left">{label}</span>
            <span className={`rounded-full px-2 py-0.5 text-[11px] tabular-nums ${filters.folder === key ? 'bg-brand-100 text-brand-700 dark:bg-brand-900/60 dark:text-brand-200' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'}`}>{counts[key] ?? 0}</span>
        </button>)}
    </div>;
}

function MailRow({ conversation, active, timezone, onOpen }) {
    const last = conversation.last_message;
    const unread = conversation.unread_count > 0;
    const account = conversation.channel_account;
    return <button
        type="button"
        onClick={() => onOpen(conversation)}
        className={`block w-full border-b border-neutral-100 px-4 py-3.5 text-left transition dark:border-neutral-800 ${active ? 'border-l-4 border-l-brand-600 bg-brand-50/80 dark:bg-brand-950/20' : 'hover:bg-neutral-50 dark:hover:bg-neutral-800/60'}`}
    >
        <div className="mb-1 flex items-center gap-2">
            <span className={`min-w-0 flex-1 truncate text-sm ${unread ? 'font-bold text-neutral-950 dark:text-white' : 'font-semibold text-neutral-700 dark:text-neutral-200'}`}>{contactName(conversation)}</span>
            <time className="shrink-0 text-[11px] text-neutral-400">{formatMailTime(conversation.last_message_at, timezone)}</time>
        </div>
        <p className={`truncate text-sm ${unread ? 'font-semibold text-neutral-800 dark:text-neutral-100' : 'text-neutral-600 dark:text-neutral-300'}`}>{subjectOf(conversation)}</p>
        <p className="mt-0.5 line-clamp-2 text-xs leading-5 text-neutral-400">{last?.body || 'No message preview'}</p>
        <div className="mt-2 flex items-center gap-2">
            <span className="max-w-40 truncate rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{account?.display_name || PROVIDER_LABELS[account?.provider] || 'Mailbox'}</span>
            <span className={`ml-auto h-2 w-2 rounded-full ${conversation.status === 'resolved' ? 'bg-neutral-300' : conversation.status === 'snoozed' ? 'bg-amber-400' : 'bg-emerald-500'}`} title={conversation.status} />
            {unread && <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">{Math.min(conversation.unread_count, 99)}</span>}
        </div>
    </button>;
}

function MessageBlock({ message, contact, mailbox }) {
    const outbound = message.direction === 'out';
    const sender = safeText(outbound ? (message.user?.name || mailbox?.display_name) : contactName({ contact }), outbound ? 'Your team' : 'Unknown sender');
    const senderEmail = safeText(outbound ? mailbox?.meta_json?.email : contact?.email, 'unknown');
    const body = safeText(message.body, '(empty message)');
    return <article className={`border-b border-neutral-100 px-5 py-5 dark:border-neutral-800 sm:px-7 ${outbound ? 'bg-brand-50/30 dark:bg-brand-950/10' : 'bg-white dark:bg-neutral-900'}`}>
        <div className="mb-4 flex items-start gap-3">
            <div className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-sm font-bold ${outbound ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-200'}`}>{sender?.[0]?.toUpperCase() || '?'}</div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="truncate text-sm font-semibold text-neutral-900 dark:text-white">{sender}</p>
                    <span className="truncate text-xs text-neutral-400">&lt;{senderEmail || 'unknown'}&gt;</span>
                </div>
                <p className="text-xs text-neutral-400">{outbound ? `to ${contact?.email || 'recipient'}` : `to ${mailbox?.display_name || 'your team'}`}</p>
            </div>
            <div className="shrink-0 text-right">
                <time className="text-xs text-neutral-400">{formatMessageTime(message.sent_at)}</time>
                {outbound && <p className={`mt-1 text-[10px] font-medium ${message.status === 'failed' ? 'text-red-500' : 'text-neutral-400'}`}>{message.status}</p>}
            </div>
        </div>
        <div className="whitespace-pre-wrap break-words text-sm leading-7 text-neutral-700 dark:text-neutral-200">{body}</div>
        {message.payload?.has_attachments && <span className="mt-4 inline-flex rounded-lg bg-neutral-100 px-2.5 py-1 text-xs text-neutral-500 dark:bg-neutral-800">Attachment included in source mailbox</span>}
    </article>;
}

export default function EmailInbox({
    conversations: initialConversations,
    filters,
    counts: initialCounts,
    accounts = [],
    selectedConversation,
    messages: initialMessages = [],
}) {
    const { props } = usePage();
    const timezone = props.timezone || 'Asia/Dhaka';
    const [conversations, setConversations] = useState(initialConversations);
    const [counts, setCounts] = useState(initialCounts);
    const [messages, setMessages] = useState(initialMessages);
    const [search, setSearch] = useState(filters.search || '');
    const [reply, setReply] = useState('');
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState('');
    const initialSearch = useRef(true);
    const bottomRef = useRef(null);

    // Inertia can replace page props without remounting this component; keep
    // the pollable local copies aligned with the latest server response.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    useEffect(() => setConversations(initialConversations), [initialConversations]);
    // eslint-disable-next-line react-hooks/set-state-in-effect
    useEffect(() => setCounts(initialCounts), [initialCounts]);
    // eslint-disable-next-line react-hooks/set-state-in-effect
    useEffect(() => setMessages(initialMessages), [initialMessages, selectedConversation?.id]);
    useEffect(() => {
        // Never return scrollIntoView's implementation-specific return value:
        // React treats any returned value as an effect cleanup function.
        bottomRef.current?.scrollIntoView({ block: 'end' });
    }, [selectedConversation?.id, messages.length]);

    const params = useMemo(() => ({
        folder: filters.folder,
        account_id: filters.account_id || undefined,
        search: filters.search || undefined,
        conversation: selectedConversation?.uuid || undefined,
    }), [filters, selectedConversation?.uuid]);

    useEffect(() => {
        if (initialSearch.current) {
            initialSearch.current = false;
            return;
        }
        const timer = setTimeout(() => {
            if (search === (filters.search || '')) return;
            router.get(route('client.inbox.email-inbox'), { ...params, search: search || undefined, conversation: undefined }, { preserveState: true, replace: true, preserveScroll: true });
        }, 350);
        return () => clearTimeout(timer);
    }, [search, filters.search, params]);

    useEffect(() => {
        let stopped = false;
        const poll = () => axios.get(route('client.inbox.email.poll'), { params: { folder: filters.folder, account_id: filters.account_id, search: filters.search } })
            .then(({ data }) => {
                if (!stopped) {
                    setConversations(data.conversations);
                    setCounts(data.counts);
                }
            }).catch(() => {});
        const timer = window.setInterval(poll, 5000);
        return () => { stopped = true; window.clearInterval(timer); };
    }, [filters.folder, filters.account_id, filters.search]);

    useEffect(() => {
        if (!selectedConversation) return;
        let stopped = false;
        const poll = () => {
            const after = messages.length ? messages[messages.length - 1].id : 0;
            axios.get(route('client.inbox.messages.poll', selectedConversation.uuid), { params: { after } })
                .then(({ data }) => {
                    if (!stopped && data.messages?.length) {
                        setMessages(current => [...current, ...data.messages.filter(item => !current.some(existing => existing.id === item.id))]);
                    }
                }).catch(() => {});
        };
        const timer = window.setInterval(poll, 3000);
        return () => { stopped = true; window.clearInterval(timer); };
    }, [selectedConversation, messages]);

    const navigate = (next) => router.get(route('client.inbox.email-inbox'), next, { preserveState: true, replace: true, preserveScroll: true });
    const openConversation = conversation => navigate({ ...params, conversation: conversation.uuid });
    const selectFolder = folder => navigate({ folder, account_id: filters.account_id || undefined, search: filters.search || undefined });
    const selectAccount = accountId => navigate({ folder: filters.folder, account_id: accountId || undefined, search: filters.search || undefined });

    const submitReply = async event => {
        event.preventDefault();
        const body = reply.trim();
        if (!body || !selectedConversation || sending) return;
        setSending(true);
        setSendError('');
        try {
            const { data } = await axios.post(route('client.inbox.reply', selectedConversation.uuid), { body, type: 'text' }, { headers: { Accept: 'application/json' } });
            if (data.message) setMessages(current => [...current, data.message]);
            setReply('');
            if (data.error) setSendError(data.error);
        } catch (error) {
            setSendError(error.response?.data?.message || 'The reply could not be sent.');
        } finally {
            setSending(false);
        }
    };

    const setStatus = status => router.post(route('client.inbox.status', selectedConversation.uuid), { status }, { preserveScroll: true });
    const selectedSubject = safeText(messages.find(message => safeText(message.payload?.subject))?.payload?.subject) || subjectOf(selectedConversation);
    const selectedMailbox = selectedConversation?.channel_account;

    return <InboxLayout>
        <Head title="Master Email Inbox" />
        <div className="flex min-h-0 flex-1 overflow-hidden bg-white dark:bg-neutral-900">
            <aside className="hidden w-56 shrink-0 flex-col border-r border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900 xl:flex">
                <div className="border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                    <div className="flex items-center gap-2 text-lg font-bold text-neutral-900 dark:text-white"><Mail className="h-5 w-5 text-brand-600" />Master Inbox</div>
                    <p className="mt-1 text-xs text-neutral-400">All connected email accounts</p>
                </div>
                <FolderNav filters={filters} counts={counts} onFolder={selectFolder} />
                <div className="mt-auto border-t border-neutral-200 p-3 dark:border-neutral-800">
                    <Link href={route('client.inbox.email.index')} className="flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800"><Settings2 className="h-4 w-4" />Email Setup</Link>
                </div>
            </aside>

            <section className={`${selectedConversation ? 'hidden lg:flex' : 'flex'} w-full shrink-0 flex-col border-r border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900 sm:w-[390px]`}>
                <header className="space-y-3 border-b border-neutral-200 px-4 py-4 dark:border-neutral-800">
                    <div className="flex items-center justify-between gap-3">
                        <div><h1 className="font-bold text-neutral-900 dark:text-white">{FOLDERS.find(folder => folder.key === filters.folder)?.label || 'Inbox'}</h1><p className="text-xs text-neutral-400">{conversations.total} email threads</p></div>
                        <button type="button" onClick={() => router.reload({ only: ['conversations', 'counts'] })} className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800"><RefreshCw className="h-4 w-4" /></button>
                    </div>
                    <div className="relative"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Search sender, email or message" className="w-full rounded-xl border-0 bg-neutral-100 py-2.5 pl-10 pr-3 text-sm focus:ring-2 focus:ring-brand-500 dark:bg-neutral-800" /></div>
                    <select value={filters.account_id || ''} onChange={event => selectAccount(event.target.value)} className="w-full rounded-xl border-neutral-200 bg-white py-2 text-xs text-neutral-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"><option value="">All connected accounts</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.display_name} · {account.email || PROVIDER_LABELS[account.provider]}</option>)}</select>
                    <div className="flex gap-1 overflow-x-auto xl:hidden">{FOLDERS.map(folder => <button key={folder.key} type="button" onClick={() => selectFolder(folder.key)} className={`shrink-0 rounded-full px-3 py-1.5 text-xs font-medium ${filters.folder === folder.key ? 'bg-brand-600 text-white' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'}`}>{folder.label} {counts[folder.key] ?? 0}</button>)}</div>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto">
                    {conversations.data.length ? conversations.data.map(conversation => <MailRow key={conversation.id} conversation={conversation} active={selectedConversation?.id === conversation.id} timezone={timezone} onOpen={openConversation} />) : <div className="flex h-full flex-col items-center justify-center px-8 text-center"><MailOpen className="mb-3 h-10 w-10 text-neutral-200 dark:text-neutral-700" /><p className="font-semibold text-neutral-500">No emails here</p><p className="mt-1 text-sm text-neutral-400">Try another folder, mailbox, or search.</p></div>}
                </div>
                {(conversations.prev_page_url || conversations.next_page_url) && <div className="flex items-center justify-between border-t border-neutral-200 px-4 py-3 text-xs text-neutral-400 dark:border-neutral-800"><button disabled={!conversations.prev_page_url} onClick={() => conversations.prev_page_url && router.visit(conversations.prev_page_url)} className="rounded-lg p-1.5 disabled:opacity-30"><ChevronLeft className="h-4 w-4" /></button><span>Page {conversations.current_page} of {conversations.last_page}</span><button disabled={!conversations.next_page_url} onClick={() => conversations.next_page_url && router.visit(conversations.next_page_url)} className="rounded-lg p-1.5 disabled:opacity-30"><ChevronRight className="h-4 w-4" /></button></div>}
            </section>

            <main className={`${selectedConversation ? 'flex' : 'hidden lg:flex'} min-w-0 flex-1 flex-col bg-neutral-50 dark:bg-neutral-950`}>
                {!selectedConversation ? <div className="flex h-full flex-col items-center justify-center px-6 text-center"><div className="mb-5 flex h-20 w-20 items-center justify-center rounded-3xl bg-white shadow-sm ring-1 ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-800"><MailOpen className="h-9 w-9 text-brand-400" /></div><h2 className="text-lg font-bold text-neutral-700 dark:text-neutral-200">Select an email to read</h2><p className="mt-2 max-w-sm text-sm text-neutral-400">Choose a conversation from the email list. Messages from other Cerqle channels never appear here.</p></div> : <>
                    <header className="border-b border-neutral-200 bg-white px-4 py-4 dark:border-neutral-800 dark:bg-neutral-900 sm:px-6">
                        <div className="flex items-start gap-3">
                            <button type="button" onClick={() => navigate({ folder: filters.folder, account_id: filters.account_id || undefined, search: filters.search || undefined })} className="mt-0.5 rounded-lg p-2 text-neutral-500 hover:bg-neutral-100 lg:hidden"><ArrowLeft className="h-5 w-5" /></button>
                            <div className="min-w-0 flex-1"><h2 className="truncate text-lg font-bold text-neutral-900 dark:text-white">{selectedSubject}</h2><div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-400"><span>{contactName(selectedConversation)}</span><span>·</span><span>{selectedConversation.contact?.email}</span><span>·</span><span className="rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">{selectedMailbox?.display_name}</span></div></div>
                            <button type="button" onClick={() => setStatus(selectedConversation.status === 'resolved' ? 'open' : 'resolved')} className={`flex shrink-0 items-center gap-2 rounded-xl px-3 py-2 text-xs font-semibold ${selectedConversation.status === 'resolved' ? 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300'}`}>{selectedConversation.status === 'resolved' ? <Circle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}{selectedConversation.status === 'resolved' ? 'Reopen' : 'Resolve'}</button>
                        </div>
                    </header>
                    <div className="min-h-0 flex-1 overflow-y-auto">{messages.map(message => <MessageBlock key={message.id} message={message} contact={selectedConversation.contact} mailbox={selectedMailbox} />)}<div ref={bottomRef} /></div>
                    <form onSubmit={submitReply} className="border-t border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900 sm:p-5">
                        <div className="rounded-2xl border border-neutral-200 bg-white shadow-sm focus-within:border-brand-400 focus-within:ring-2 focus-within:ring-brand-100 dark:border-neutral-700 dark:bg-neutral-800 dark:focus-within:ring-brand-950"><textarea value={reply} onChange={event => setReply(event.target.value)} onKeyDown={event => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') submitReply(event); }} rows={4} placeholder={`Reply to ${selectedConversation.contact?.email || 'customer'}…`} className="w-full resize-none rounded-2xl border-0 bg-transparent px-4 py-3 text-sm focus:ring-0" /><div className="flex items-center justify-between border-t border-neutral-100 px-3 py-2 dark:border-neutral-700"><p className="text-[11px] text-neutral-400">Sending from {selectedMailbox?.meta_json?.email || selectedMailbox?.display_name} · Ctrl/⌘ + Enter</p><button disabled={sending || !reply.trim()} className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40"><Send className="h-4 w-4" />{sending ? 'Sending…' : 'Send'}</button></div></div>
                        {sendError && <p className="mt-2 text-xs text-red-600">{sendError}</p>}
                    </form>
                </>}
            </main>
        </div>
    </InboxLayout>;
}
