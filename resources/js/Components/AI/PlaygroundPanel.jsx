import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bot, MessageSquare, Send, Type, X } from 'lucide-react';

/**
 * Try the bot before customers do.
 *
 * Extracted from the bots list so the bot's own page shows the same panel
 * rather than a second implementation that could drift from it.
 */
export default function PlaygroundPanel({ chatbot }) {
    const { t } = useTranslation();
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const bottomRef = useRef(null);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages, loading]);

    const send = async () => {
        if (!input.trim() || loading) return;
        const userMsg = { role: 'user', content: input };
        setMessages(prev => [...prev, userMsg]);
        setInput('');
        setLoading(true);
        try {
            const res = await fetch(route('client.ai.chatbots.playground', chatbot.uuid), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                },
                body: JSON.stringify({ message: userMsg.content, history: messages }),
            });
            const data = await res.json();
            setMessages(prev => [...prev, {
                role: res.ok ? 'assistant' : 'error',
                content: data.reply ?? data.error ?? t('ai.playground_error'),
                answer: data.answer,
            }]);
        } catch {
            setMessages(prev => [...prev, { role: 'error', content: t('ai.playground_error') }]);
        } finally {
            setLoading(false);
        }
    };

    const handleKey = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
    };

    return (
        <div className="flex flex-col h-[28rem] bg-neutral-50 dark:bg-neutral-800/50 rounded-xl border border-neutral-200 dark:border-neutral-700 overflow-hidden">
            <div className="flex items-center gap-2 px-4 py-2.5 border-b border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                <div className="w-2 h-2 rounded-full bg-green-500" />
                <span className="text-xs font-medium text-neutral-600 dark:text-neutral-400">{chatbot.name} — {t('ai.playground')}</span>
                {messages.length > 0 && (
                    <button onClick={() => setMessages([])} className="ml-auto text-xs text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">{t('ai.clear')}</button>
                )}
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-3">
                {messages.length === 0 && !loading && (
                    <div className="flex flex-col items-center justify-center h-full text-center space-y-2">
                        <MessageSquare className="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                        <p className="text-sm text-neutral-400 dark:text-neutral-500">{t('ai.playground_empty')}</p>
                    </div>
                )}
                {messages.map((m, i) => {
                    const isUser = m.role === 'user';
                    const isError = m.role === 'error';

                    return (
                        <div key={i} className={`flex gap-2 ${isUser ? 'flex-row-reverse' : 'flex-row'}`}>
                            <div className={`shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold ${isUser ? 'bg-brand-600 text-white' : isError ? 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300' : 'bg-neutral-200 dark:bg-neutral-700 text-neutral-600 dark:text-neutral-300'}`}>
                                {isUser ? 'U' : isError ? <AlertTriangle className="h-3.5 w-3.5" /> : <Bot className="h-3.5 w-3.5" />}
                            </div>
                            <div className={`rounded-2xl px-3.5 py-2 text-sm leading-relaxed ${isUser ? 'max-w-[75%] bg-brand-600 text-white rounded-tr-sm whitespace-pre-wrap break-words' : isError ? 'max-w-[85%] border border-red-200 bg-red-50 text-red-800 dark:border-red-900/60 dark:bg-red-900/20 dark:text-red-200 rounded-tl-sm whitespace-pre-wrap break-words' : 'max-w-[85%] bg-white dark:bg-neutral-700 text-neutral-900 dark:text-neutral-100 shadow-sm rounded-tl-sm'}`}>
                                {isUser || isError ? m.content : <MarkdownLite content={m.content} />}
                                {m.answer?.answer_origin && (
                                    <p className="mt-1.5 border-t border-neutral-100 pt-1 text-[10px] uppercase tracking-wide text-neutral-400 dark:border-neutral-600">
                                        {m.answer.answer_origin.replaceAll('_', ' ')} · {m.answer.response_mode}
                                    </p>
                                )}
                            </div>
                        </div>
                    );
                })}
                {loading && (
                    <div className="flex gap-2">
                        <div className="shrink-0 w-7 h-7 rounded-full bg-neutral-200 dark:bg-neutral-700 flex items-center justify-center">
                            <Bot className="h-3.5 w-3.5 text-neutral-500" />
                        </div>
                        <div className="bg-white dark:bg-neutral-700 rounded-2xl rounded-tl-sm px-4 py-2.5 shadow-sm flex items-center gap-1">
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400 animate-bounce [animation-delay:0ms]" />
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400 animate-bounce [animation-delay:150ms]" />
                            <span className="w-1.5 h-1.5 rounded-full bg-neutral-400 animate-bounce [animation-delay:300ms]" />
                        </div>
                    </div>
                )}
                <div ref={bottomRef} />
            </div>

            <div className="p-3 border-t border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                <div className="flex items-center gap-2 rounded-xl border border-neutral-200 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-3 py-1.5">
                    <input
                        type="text"
                        value={input}
                        onChange={e => setInput(e.target.value)}
                        onKeyDown={handleKey}
                        placeholder={t('ai.type_a_message')}
                        className="flex-1 bg-transparent text-sm outline-none text-neutral-900 dark:text-neutral-100 placeholder-neutral-400"
                    />
                    <button
                        onClick={send}
                        disabled={loading || !input.trim()}
                        className="shrink-0 w-7 h-7 rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center transition"
                    >
                        <Send className="h-3.5 w-3.5 text-white" />
                    </button>
                </div>
            </div>
        </div>
    );
}
