import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import { useConfirm } from '@/Components/ui/ConfirmProvider';
import ClientLayout from '@/Layouts/ClientLayout';
import PlaygroundPanel from '@/Components/AI/PlaygroundPanel';
import EmptyState from '@/Components/EmptyState';
import { Plus, Bot, Trash2, Play, Settings, Send, X, BookOpen, Zap, MessageSquare, AlertTriangle } from 'lucide-react';
import { useState, useRef, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import MarkdownLite from '@/Components/MarkdownLite';

const TONE_OPTIONS = ['professional', 'friendly', 'formal', 'casual'];

const TONE_COLORS = {
    professional: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    friendly: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    formal: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    casual: 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300',
};

function ToggleSwitch({ checked, onChange }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none ${checked ? 'bg-brand-600' : 'bg-neutral-200 dark:bg-neutral-700'}`}
        >
            <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition duration-200 ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

function ChatbotCard({ chatbot, knowledgeBases }) {
    const confirm = useConfirm();
    const { t } = useTranslation();
    // Test opens in place; Configure now goes to the bot's own page.
    const [tab, setTab] = useState(null);

    const handleDelete = async () => {
        if (await confirm(t('ai.delete_chatbot_confirm', { name: chatbot.name }))) {
            router.delete(route('client.ai.chatbots.destroy', chatbot.uuid), { preserveScroll: true });
        }
    };

    const linkedKb = knowledgeBases.find(kb => kb.id == chatbot.ai_kb_id);

    const toggleTab = (t) => setTab(prev => prev === t ? null : t);

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden transition hover:shadow-sm">
            {/* Card Header */}
            <div className="flex items-center gap-3 px-5 py-4">
                <div className="w-9 h-9 rounded-xl bg-brand-50 dark:bg-brand-900/30 flex items-center justify-center shrink-0">
                    <Bot className="h-5 w-5 text-brand-600 dark:text-brand-400" />
                </div>

                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-semibold text-neutral-900 dark:text-neutral-100 truncate">{chatbot.name}</span>
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${chatbot.enabled ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'}`}>
                            {chatbot.enabled ? t('common.active') : t('ai.disabled')}
                        </span>
                        {chatbot.tone && (
                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${TONE_COLORS[chatbot.tone] ?? 'bg-neutral-100 text-neutral-500'}`}>
                                {t(`ai.tone_${chatbot.tone}`)}
                            </span>
                        )}
                        <span className="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                            {(chatbot.answer_scope ?? 'business_only').replaceAll('_', ' ')}
                        </span>
                    </div>
                    <div className="flex items-center gap-3 mt-0.5">
                        {linkedKb ? (
                            <span className="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                                <BookOpen className="h-3 w-3" /> {linkedKb.name}
                            </span>
                        ) : (
                            <span className="text-xs text-neutral-400 dark:text-neutral-500 italic">{t('ai.no_knowledge_base')}</span>
                        )}
                        {chatbot.system_prompt && (
                            <span className="text-xs text-neutral-400 dark:text-neutral-500 truncate max-w-[180px]">&ldquo;{chatbot.system_prompt}&rdquo;</span>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-1 shrink-0">
                    <button
                        onClick={() => toggleTab('playground')}
                        className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition ${tab === 'playground' ? 'bg-brand-50 dark:bg-brand-900/30 text-brand-600 dark:text-brand-400' : 'text-neutral-500 hover:bg-neutral-100 dark:hover:bg-neutral-800 hover:text-neutral-700 dark:hover:text-neutral-300'}`}
                    >
                        <Play className="h-3.5 w-3.5" /> {t('ai.test')}
                    </button>
                    <Link
                        href={route('client.ai.chatbots.show', chatbot.uuid)}
                        className="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-neutral-300"
                    >
                        <Settings className="h-3.5 w-3.5" /> {t('ai.configure')}
                    </Link>
                    <button onClick={handleDelete} className="rounded-lg p-1.5 text-neutral-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                        <Trash2 className="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>

            {/* Expandable Panels */}
            {tab === 'playground' && (
                <div className="border-t border-neutral-100 dark:border-neutral-800 px-5 pb-5 pt-4">
                    <PlaygroundPanel chatbot={chatbot} />
                </div>
            )}

        </div>
    );
}

export default function AiChatbotsIndex({ chatbots, knowledgeBases }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const { data, setData, post, processing, reset, errors } = useForm({ name: '' });

    return (
        <ClientLayout title={t('ai.chatbots_title')}>
            <Head title={`${t('ai.chatbots_title')} · AI Automations`} />
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.chatbots_heading')}</h2>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('ai.chatbots_subtitle')}</p>
                    </div>
                    {/* Goes to the guided create: the bot and the knowledge it
                        answers from are made together, so there is no second
                        object to find and attach afterwards. */}
                    <Link
                        href={route('client.ai.chatbots.create')}
                        className="flex items-center gap-2 rounded-xl bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition shadow-sm"
                    >
                        <Plus className="h-4 w-4" /> {t('ai.new_chatbot')}
                    </Link>
                </div>

                {/* Stats bar */}
                {chatbots.length > 0 && (
                    <div className="grid grid-cols-3 gap-3">
                        {[
                            { label: t('ai.stat_total_bots'), value: chatbots.length, icon: Bot, color: 'text-brand-600 dark:text-brand-400', bg: 'bg-brand-50 dark:bg-brand-900/20' },
                            { label: t('common.active'), value: chatbots.filter(c => c.enabled).length, icon: Zap, color: 'text-green-600 dark:text-green-400', bg: 'bg-green-50 dark:bg-green-900/20' },
                            { label: t('ai.stat_with_kb'), value: chatbots.filter(c => c.ai_kb_id).length, icon: BookOpen, color: 'text-purple-600 dark:text-purple-400', bg: 'bg-purple-50 dark:bg-purple-900/20' },
                        ].map(stat => (
                            <div key={stat.label} className="rounded-xl border border-neutral-100 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-4 flex items-center gap-3">
                                <div className={`w-9 h-9 rounded-lg ${stat.bg} flex items-center justify-center shrink-0`}>
                                    <stat.icon className={`h-4.5 w-4.5 ${stat.color}`} />
                                </div>
                                <div>
                                    <p className="text-xl font-bold text-neutral-900 dark:text-neutral-100 leading-none">{stat.value}</p>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{stat.label}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {flash.success && (
                    <div className="flex items-center gap-2 rounded-xl bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 px-4 py-3 text-sm">
                        <div className="w-1.5 h-1.5 rounded-full bg-green-500 shrink-0" />
                        {flash.success}
                    </div>
                )}

                {/* Chatbot list */}
                <div className="space-y-3">
                    {chatbots.map(cb => (
                        <ChatbotCard key={cb.id} chatbot={cb} knowledgeBases={knowledgeBases} />
                    ))}
                    {chatbots.length === 0 && (
                        <EmptyState
                            icon={<Bot className="h-8 w-8" />}
                            title={t('ai.chatbots_empty_title')}
                            description={t('ai.chatbots_empty_description')}
                            action={{ label: t('ai.new_chatbot'), href: route('client.ai.chatbots.create') }}
                        />
                    )}
                </div>
            </div>

        </ClientLayout>
    );
}
