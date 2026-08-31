import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { X, Search, User, Phone, Mail, Globe, Send, Loader2, MessageSquarePlus, ChevronRight } from 'lucide-react';
import { ChannelBrandIcon, CHANNEL_LABELS } from '@/Components/BrandIcons';
import { useTranslation } from 'react-i18next';

function ContactAvatar({ contact, size = 'md' }) {
    const name = [contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.phone_e164 || '?';
    const cls = size === 'lg' ? 'h-12 w-12 text-base' : 'h-9 w-9 text-sm';
    const initials = name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase() || '?';

    if (contact.avatar_url) {
        return (
            <img
                src={contact.avatar_url}
                alt={name}
                className={`${cls} rounded-full object-cover shrink-0`}
                onError={e => {
                    e.target.style.display = 'none';
                    e.target.nextSibling && (e.target.nextSibling.style.display = 'flex');
                }}
            />
        );
    }
    return (
        <div className={`${cls} rounded-full bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center font-semibold text-brand-700 dark:text-brand-300 shrink-0`}>
            {initials}
        </div>
    );
}

function ContactRow({ contact, onSelect, isSelected }) {
    const name = [contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.phone_e164 || 'Unknown';
    return (
        <button
            type="button"
            onClick={() => onSelect(contact)}
            className={`w-full flex items-center gap-3 px-4 py-3 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition text-left border-b border-neutral-100 dark:border-neutral-800 last:border-0 ${isSelected ? 'bg-brand-50 dark:bg-brand-900/20' : ''}`}
        >
            <ContactAvatar contact={contact} />
            <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-neutral-900 dark:text-neutral-100 truncate">{name}</p>
                <p className="text-xs text-neutral-400 truncate">
                    {contact.phone_e164 && <span className="mr-2">{contact.phone_e164}</span>}
                    {contact.email && <span>{contact.email}</span>}
                </p>
            </div>
            {isSelected && <ChevronRight className="h-4 w-4 text-brand-600 shrink-0" />}
        </button>
    );
}

function getChannelReachability(channel, contact) {
    if (!contact) return { reachable: false, reason: 'missing_contact', label: 'No contact selected' };

    if (channel === 'whatsapp' || channel === 'sms') {
        const hasPhone = Boolean(contact.phone_e164 && contact.phone_e164.trim() !== '');
        if (hasPhone) return { reachable: true };
        return {
            reachable: false,
            reason: 'missing_phone',
            label: 'Missing phone number',
            actionType: 'phone',
            actionLabel: '+ Add Phone',
        };
    }

    if (channel === 'email') {
        const hasEmail = Boolean(contact.email && contact.email.trim() !== '');
        if (hasEmail) return { reachable: true };
        return {
            reachable: false,
            reason: 'missing_email',
            label: 'Missing email address',
            actionType: 'email',
            actionLabel: '+ Add Email',
        };
    }

    if (channel === 'messenger') {
        const hasSocial = Boolean(contact.has_messenger_thread || contact.custom_fields?.messenger_psid);
        if (hasSocial) return { reachable: true };
        return {
            reachable: false,
            reason: 'inbound_only',
            label: 'Inbound only (no social thread)',
        };
    }

    if (channel === 'instagram') {
        const hasSocial = Boolean(contact.has_instagram_thread || contact.custom_fields?.instagram_scoped_id);
        if (hasSocial) return { reachable: true };
        return {
            reachable: false,
            reason: 'inbound_only',
            label: 'Inbound only (no social thread)',
        };
    }

    if (channel === 'webchat') {
        const hasWeb = Boolean(contact.has_webchat_thread || contact.custom_fields?.webchat_visitor_id);
        if (hasWeb) return { reachable: true };
        return {
            reachable: false,
            reason: 'no_web_session',
            label: 'No active web session',
        };
    }

    return { reachable: true };
}

export default function NewConversationModal({ onClose }) {
    const { t } = useTranslation();
    const [step, setStep] = useState('contact'); // 'contact' | 'channel' | 'compose'
    const [query, setQuery] = useState('');
    const [contacts, setContacts] = useState([]);
    const [loadingContacts, setLoadingContacts] = useState(false);
    const [selectedContact, setSelectedContact] = useState(null);
    const [channelAccounts, setChannelAccounts] = useState([]);
    const [loadingChannels, setLoadingChannels] = useState(false);
    const [selectedAccount, setSelectedAccount] = useState(null);
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState('');

    // Inline quick-add state for phone/email
    const [editingField, setEditingField] = useState(null); // 'phone' | 'email' | null
    const [editValue, setEditValue] = useState('');
    const [savingField, setSavingField] = useState(false);
    const [fieldError, setFieldError] = useState('');

    const searchRef = useRef(null);
    const overlayRef = useRef(null);

    // Close on overlay click
    const handleOverlay = (e) => {
        if (e.target === overlayRef.current) onClose();
    };

    // Close on Escape
    useEffect(() => {
        const handler = (e) => { if (e.key === 'Escape') onClose(); };
        document.removeEventListener('keydown', handler);
        document.addEventListener('keydown', handler);
        return () => document.removeEventListener('keydown', handler);
    }, [onClose]);

    // Auto-focus search
    useEffect(() => {
        setTimeout(() => searchRef.current?.focus(), 50);
    }, []);

    // Search contacts with debounce
    useEffect(() => {
        setLoadingContacts(true);
        const timer = setTimeout(() => {
            axios.get(route('client.inbox.contacts.search'), { params: { q: query } })
                .then(r => setContacts(r.data ?? []))
                .catch(() => {})
                .finally(() => setLoadingContacts(false));
        }, 300);
        return () => clearTimeout(timer);
    }, [query]);

    // Load channel accounts when contact selected
    const selectContact = (contact) => {
        setSelectedContact(contact);
        setStep('channel');
        setEditingField(null);
        setFieldError('');
        setLoadingChannels(true);
        axios.get(route('client.inbox.channel-accounts'))
            .then(r => setChannelAccounts(r.data ?? []))
            .catch(() => {})
            .finally(() => setLoadingChannels(false));
    };

    const selectAccount = (account) => {
        setSelectedAccount(account);
        setStep('compose');
    };

    const startEditing = (type) => {
        setEditingField(type);
        setEditValue(type === 'phone' ? (selectedContact?.phone_e164 ?? '') : (selectedContact?.email ?? ''));
        setFieldError('');
    };

    const cancelEditing = () => {
        setEditingField(null);
        setEditValue('');
        setFieldError('');
    };

    const handleSaveField = (e) => {
        e?.preventDefault?.();
        if (!selectedContact) return;
        setFieldError('');

        const trimmed = editValue.trim();
        if (!trimmed) {
            setFieldError(editingField === 'phone' ? 'Phone number is required.' : 'Email address is required.');
            return;
        }

        if (editingField === 'phone' && !trimmed.startsWith('+')) {
            setFieldError('Please enter international format with country code (e.g. +14155552671).');
            return;
        }

        if (editingField === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
            setFieldError('Please enter a valid email address.');
            return;
        }

        setSavingField(true);
        const payload = editingField === 'phone' ? { phone_e164: trimmed } : { email: trimmed };

        axios.put(route('client.contacts.update', selectedContact.uuid || selectedContact.id), payload)
            .then(res => {
                const updated = res.data?.contact || { ...selectedContact, ...payload };
                setSelectedContact(updated);
                setContacts(prev => prev.map(c => c.id === updated.id ? { ...c, ...updated } : c));
                setEditingField(null);
                setEditValue('');
            })
            .catch(err => {
                const msg = err.response?.data?.errors?.phone_e164?.[0]
                    ?? err.response?.data?.errors?.email?.[0]
                    ?? err.response?.data?.message
                    ?? 'Failed to update contact details.';
                setFieldError(msg);
            })
            .finally(() => {
                setSavingField(false);
            });
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setError('');
        setSubmitting(true);
        axios.post(route('client.inbox.start'), {
            contact_id: selectedContact.id,
            channel_account_id: selectedAccount.id,
            body: message.trim() || undefined,
        })
            .then(r => {
                router.visit(r.request?.responseURL ?? route('client.inbox.index'));
            })
            .catch(err => {
                setError(err.response?.data?.message ?? t('inbox.something_went_wrong'));
                setSubmitting(false);
            });
    };

    const contactName = selectedContact
        ? [selectedContact.first_name, selectedContact.last_name].filter(Boolean).join(' ') || selectedContact.phone_e164 || selectedContact.email || 'Unknown'
        : '';

    return (
        <div
            ref={overlayRef}
            onClick={handleOverlay}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
        >
            <div className="bg-white dark:bg-neutral-900 rounded-2xl shadow-2xl w-full max-w-lg flex flex-col overflow-hidden max-h-[90vh]">

                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-neutral-200 dark:border-neutral-700 shrink-0">
                    <div className="flex items-center gap-2.5">
                        <div className="h-8 w-8 rounded-xl bg-brand-100 dark:bg-brand-900/30 flex items-center justify-center">
                            <MessageSquarePlus className="h-4 w-4 text-brand-600 dark:text-brand-400" />
                        </div>
                        <div>
                            <h2 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{t('inbox.new_conversation')}</h2>
                            <p className="text-xs text-neutral-400">
                                {step === 'contact' && t('inbox.search_select_contact')}
                                {step === 'channel' && t('inbox.pick_channel_for', { name: contactName })}
                                {step === 'compose' && t('inbox.message_via', { channel: CHANNEL_LABELS[selectedAccount?.channel] ?? selectedAccount?.channel })}
                            </p>
                        </div>
                    </div>
                    <button onClick={onClose} className="p-1.5 rounded-lg text-neutral-400 hover:text-neutral-600 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        <X className="h-4 w-4" />
                    </button>
                </div>

                {/* Breadcrumb */}
                <div className="flex items-center gap-1 px-5 py-2 bg-neutral-50 dark:bg-neutral-800/50 text-xs text-neutral-400 shrink-0">
                    <button onClick={() => setStep('contact')} className={`${step === 'contact' ? 'text-brand-600 dark:text-brand-400 font-medium' : 'hover:text-neutral-600'} transition`}>{t('inbox.step_contact')}</button>
                    <ChevronRight className="h-3 w-3" />
                    <button onClick={() => selectedContact && setStep('channel')} className={`${step === 'channel' ? 'text-brand-600 dark:text-brand-400 font-medium' : selectedContact ? 'hover:text-neutral-600' : 'opacity-40 cursor-default'} transition`}>{t('inbox.step_channel')}</button>
                    <ChevronRight className="h-3 w-3" />
                    <span className={step === 'compose' ? 'text-brand-600 dark:text-brand-400 font-medium' : 'opacity-40'}>{t('inbox.step_compose')}</span>
                </div>

                {/* ── Step 1: Contact search ── */}
                {step === 'contact' && (
                    <div className="flex flex-col flex-1 overflow-hidden">
                        <div className="px-4 py-3 border-b border-neutral-100 dark:border-neutral-800 shrink-0">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-neutral-400 pointer-events-none" />
                                <input
                                    ref={searchRef}
                                    type="text"
                                    value={query}
                                    onChange={e => setQuery(e.target.value)}
                                    placeholder={t('inbox.search_contact')}
                                    className="w-full pl-9 pr-4 py-2 text-sm rounded-xl bg-neutral-100 dark:bg-neutral-800 border-0 focus:outline-none focus:ring-2 focus:ring-brand-500 placeholder-neutral-400"
                                />
                                {loadingContacts && (
                                    <Loader2 className="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-neutral-400 animate-spin" />
                                )}
                            </div>
                        </div>
                        <div className="flex-1 overflow-y-auto">
                            {!loadingContacts && contacts.length === 0 && (
                                <div className="flex flex-col items-center justify-center py-12 text-neutral-400">
                                    <User className="h-8 w-8 mb-2 opacity-40" />
                                    <p className="text-sm">{query ? t('inbox.no_contacts_found') : t('inbox.type_to_search_contacts')}</p>
                                </div>
                            )}
                            {contacts.map(c => (
                                <ContactRow
                                    key={c.id}
                                    contact={c}
                                    isSelected={selectedContact?.id === c.id}
                                    onSelect={selectContact}
                                />
                            ))}
                        </div>
                    </div>
                )}

                {/* ── Step 2: Channel selection ── */}
                {step === 'channel' && (
                    <div className="flex flex-col flex-1 overflow-hidden">
                        {/* Selected contact summary */}
                        <div className="flex items-center gap-3 px-5 py-3 bg-neutral-50 dark:bg-neutral-800/50 border-b border-neutral-100 dark:border-neutral-800 shrink-0">
                            <ContactAvatar contact={selectedContact} />
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 truncate">{contactName}</p>
                                <p className="text-xs text-neutral-400">{selectedContact?.phone_e164 ?? selectedContact?.email ?? 'No phone or email saved'}</p>
                            </div>
                            <button onClick={() => setStep('contact')} className="ml-auto text-xs text-brand-600 hover:underline dark:text-brand-400 shrink-0">{t('inbox.change')}</button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-4">
                            {/* Inline Edit Form for Phone / Email */}
                            {editingField && (
                                <div className="mb-4 rounded-xl border border-brand-200 dark:border-brand-800/60 bg-brand-50/50 dark:bg-brand-950/20 p-4 space-y-3">
                                    <div className="flex items-center justify-between">
                                        <p className="text-xs font-semibold text-brand-900 dark:text-brand-200">
                                            {editingField === 'phone' ? 'Add Phone Number (WhatsApp / SMS)' : 'Add Email Address'}
                                        </p>
                                        <button type="button" onClick={cancelEditing} className="text-neutral-400 hover:text-neutral-600">
                                            <X className="h-4 w-4" />
                                        </button>
                                    </div>
                                    <form onSubmit={handleSaveField} className="space-y-2">
                                        <div>
                                            <input
                                                type={editingField === 'email' ? 'email' : 'tel'}
                                                value={editValue}
                                                onChange={e => setEditValue(e.target.value)}
                                                placeholder={editingField === 'phone' ? '+14155552671' : 'customer@example.com'}
                                                autoFocus
                                                className="w-full rounded-xl border border-neutral-300 dark:border-neutral-700 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                                            />
                                            {editingField === 'phone' && (
                                                <p className="text-[11px] text-neutral-400 mt-1">
                                                    Use international E.164 format with country code (e.g. +14155552671).
                                                </p>
                                            )}
                                        </div>
                                        {fieldError && <p className="text-xs text-red-500">{fieldError}</p>}
                                        <div className="flex items-center justify-end gap-2 pt-1">
                                            <button
                                                type="button"
                                                onClick={cancelEditing}
                                                className="px-3 py-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:bg-neutral-200/50 dark:hover:bg-neutral-800 rounded-lg transition"
                                            >
                                                {t('common.cancel', 'Cancel')}
                                            </button>
                                            <button
                                                type="submit"
                                                disabled={savingField || !editValue.trim()}
                                                className="px-3 py-1.5 text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 disabled:opacity-50 rounded-lg transition flex items-center gap-1.5"
                                            >
                                                {savingField && <Loader2 className="h-3.5 w-3.5 animate-spin" />}
                                                Save & Enable
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            )}

                            {loadingChannels ? (
                                <div className="flex items-center justify-center py-10">
                                    <Loader2 className="h-6 w-6 text-brand-600 animate-spin" />
                                </div>
                            ) : channelAccounts.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-10 text-neutral-400">
                                    <p className="text-sm">{t('inbox.no_channels')}</p>
                                    <a href={route('client.inbox.setup')} className="mt-2 text-xs text-brand-600 hover:underline dark:text-brand-400">{t('inbox.set_up_channel')}</a>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    <p className="text-xs text-neutral-400 mb-3">{t('inbox.select_channel')}</p>
                                    {channelAccounts.map(acc => {
                                        const reach = getChannelReachability(acc.channel, selectedContact);
                                        const isReachable = reach.reachable;

                                        return (
                                            <div
                                                key={acc.id}
                                                onClick={() => {
                                                    if (isReachable) selectAccount(acc);
                                                }}
                                                className={`w-full flex items-center gap-3 p-3.5 rounded-xl border transition ${
                                                    isReachable
                                                        ? 'border-neutral-200 dark:border-neutral-700 hover:border-brand-400 hover:bg-brand-50 dark:hover:bg-brand-900/20 dark:hover:border-brand-600 cursor-pointer group'
                                                        : 'border-neutral-200/60 dark:border-neutral-800 bg-neutral-50/50 dark:bg-neutral-900/40 opacity-80'
                                                }`}
                                            >
                                                <div className={`h-10 w-10 rounded-xl flex items-center justify-center shrink-0 transition ${
                                                    isReachable
                                                        ? 'bg-neutral-100 dark:bg-neutral-800 group-hover:bg-brand-100 dark:group-hover:bg-brand-900/30'
                                                        : 'bg-neutral-100 dark:bg-neutral-800/60 grayscale'
                                                }`}>
                                                    <ChannelBrandIcon channel={acc.channel} className="h-5 w-5" />
                                                </div>
                                                <div className="flex-1 text-left min-w-0">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <p className={`text-sm font-medium truncate ${isReachable ? 'text-neutral-800 dark:text-neutral-200' : 'text-neutral-500 dark:text-neutral-400'}`}>
                                                            {acc.display_name || (CHANNEL_LABELS[acc.channel] ?? acc.channel)}
                                                        </p>
                                                        {!isReachable && (
                                                            <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-neutral-200/80 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-400 shrink-0">
                                                                {reach.label}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <p className="text-xs text-neutral-400 truncate">
                                                        {acc.phone_number_id ?? CHANNEL_LABELS[acc.channel]}
                                                    </p>
                                                </div>
                                                {isReachable ? (
                                                    <ChevronRight className="h-4 w-4 text-neutral-300 group-hover:text-brand-600 dark:group-hover:text-brand-400 transition shrink-0" />
                                                ) : reach.actionType ? (
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            startEditing(reach.actionType);
                                                        }}
                                                        className="text-xs font-semibold text-brand-600 dark:text-brand-400 hover:underline px-2.5 py-1 rounded-lg hover:bg-brand-50 dark:hover:bg-brand-900/20 transition shrink-0"
                                                    >
                                                        {reach.actionLabel}
                                                    </button>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* ── Step 3: Compose ── */}
                {step === 'compose' && (
                    <form onSubmit={handleSubmit} className="flex flex-col flex-1 overflow-hidden">
                        {/* Summary bar */}
                        <div className="flex items-center gap-3 px-5 py-3 bg-neutral-50 dark:bg-neutral-800/50 border-b border-neutral-100 dark:border-neutral-800 shrink-0">
                            <ContactAvatar contact={selectedContact} />
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100 truncate">{contactName}</p>
                                <p className="text-xs text-neutral-400 flex items-center gap-1">
                                    <ChannelBrandIcon channel={selectedAccount?.channel} className="h-3 w-3 shrink-0" />
                                    {selectedAccount?.display_name || (CHANNEL_LABELS[selectedAccount?.channel] ?? selectedAccount?.channel)}
                                </p>
                            </div>
                            <button type="button" onClick={() => setStep('channel')} className="text-xs text-brand-600 hover:underline dark:text-brand-400 shrink-0">{t('inbox.change')}</button>
                        </div>

                        <div className="flex-1 overflow-y-auto p-5 space-y-4">
                            {/* Contact detail card */}
                            <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 p-4 space-y-2 bg-white dark:bg-neutral-800/50">
                                <p className="text-xs font-bold uppercase tracking-wider text-neutral-400 mb-2">{t('inbox.contact_details')}</p>
                                {selectedContact?.phone_e164 && (
                                    <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400">
                                        <Phone className="h-4 w-4 shrink-0 text-neutral-400" />{selectedContact.phone_e164}
                                    </div>
                                )}
                                {selectedContact?.email && (
                                    <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400">
                                        <Mail className="h-4 w-4 shrink-0 text-neutral-400" />{selectedContact.email}
                                    </div>
                                )}
                                {selectedContact?.country && (
                                    <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400">
                                        <Globe className="h-4 w-4 shrink-0 text-neutral-400" />{selectedContact.country}
                                    </div>
                                )}
                            </div>

                            {/* Message */}
                            <div>
                                <label className="text-xs font-semibold text-neutral-600 dark:text-neutral-400 block mb-1.5">
                                    {t('inbox.opening_message')} <span className="font-normal text-neutral-400">{t('inbox.optional_paren')}</span>
                                </label>
                                <textarea
                                    value={message}
                                    onChange={e => setMessage(e.target.value)}
                                    rows={4}
                                    placeholder={t('inbox.opening_message')}
                                    className="w-full rounded-xl border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-3 py-2.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-brand-500 placeholder-neutral-400"
                                />
                                {selectedAccount?.channel === 'whatsapp' && (
                                    <div className="text-[11px] text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/60 rounded-xl p-3 mt-2 flex items-start gap-2">
                                        <span className="shrink-0 mt-0.5 text-sm">ℹ️</span>
                                        <div>
                                            <p className="font-semibold">{t('inbox.whatsapp_window_title', 'WhatsApp 24-Hour Policy')}</p>
                                            <p className="text-amber-600/90 dark:text-amber-400 mt-0.5">
                                                {t('inbox.whatsapp_freeform_warning')}
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {error && <p className="text-xs text-red-500 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">{error}</p>}
                        </div>

                        {/* Footer */}
                        <div className="px-5 py-4 border-t border-neutral-200 dark:border-neutral-700 flex gap-3 shrink-0">
                            <button type="button" onClick={() => setStep('channel')}
                                className="flex-1 rounded-xl border border-neutral-300 dark:border-neutral-600 px-4 py-2.5 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                {t('common.back')}
                            </button>
                            <button type="submit" disabled={submitting}
                                className="flex-1 rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-50 transition flex items-center justify-center gap-2">
                                {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                {submitting ? t('inbox.starting') : t('inbox.start_conversation')}
                            </button>
                        </div>
                    </form>
                )}
            </div>
        </div>
    );
}
