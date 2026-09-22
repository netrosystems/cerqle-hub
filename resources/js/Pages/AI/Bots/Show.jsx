import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import KnowledgeManager from '@/Components/AI/KnowledgeManager';
import BotSetupSteps from '@/Components/AI/BotSetupSteps';
import { ArrowLeft, Bot, Check } from 'lucide-react';

const inputCls =
    'w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm text-neutral-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/25 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-100';

function Field({ label, hint, error, children }) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-neutral-400">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-red-500">{error}</span>}
        </label>
    );
}

function Panel({ title, children }) {
    return (
        <div className="rounded-2xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
            <h3 className="mb-4 text-sm font-semibold text-neutral-900 dark:text-neutral-100">{title}</h3>
            {children}
        </div>
    );
}

export default function BotShow({ bot, knowledgeBase, documents = [], kbUploadMaxKb, kbUploadMaxMb, kbAppUploadMaxMb }) {
    const [showAdvanced, setShowAdvanced] = useState(false);
    // Name and purpose are the two the bot cannot work without.
    const profileComplete = !!(knowledgeBase?.business_name && knowledgeBase?.business_purpose);
    const indexed = documents.filter((d) => d.status === 'indexed').length;
    const failed = documents.filter((d) => d.status === 'error').length;

    // Opens on the first thing still to do, so the page starts where the work is.
    const firstOpen = !profileComplete ? 'profile' : indexed === 0 ? 'knowledge' : 'behaviour';
    const [open, setOpen] = useState(firstOpen);

    const identity = useForm({
        name: bot.name,
        system_prompt: bot.system_prompt ?? '',
        tone: bot.tone ?? 'professional',
        enabled: !!bot.enabled,
    });


    const profile = useForm({
        business_name: knowledgeBase?.business_name ?? '',
        business_purpose: knowledgeBase?.business_purpose ?? '',
        target_audience: knowledgeBase?.target_audience ?? '',
    });

    const behaviour = useForm({
        name: bot.name,
        answer_scope: bot.answer_scope ?? 'business_only',
        fallback_mode: bot.fallback_mode ?? 'clarify_then_handoff',
        fallback_reply: bot.fallback_reply ?? '',
        max_context_chunks: bot.max_context_chunks ?? 5,
        confidence_threshold: bot.confidence_threshold ?? 0.72,
        clarification_threshold: bot.clarification_threshold ?? 0.47,
    });

    const saveIdentity = (e) => {
        e.preventDefault();
        identity.put(route('client.ai.chatbots.update', bot.uuid), { preserveScroll: true });
    };
    const saveProfile = (e) => {
        e.preventDefault();
        profile.put(route('client.ai.chatbots.business', bot.uuid), { preserveScroll: true });
    };
    const saveBehaviour = (e) => {
        e.preventDefault();
        behaviour.transform((data) => ({ ...data, behaviour_set: true })).put(route('client.ai.chatbots.update', bot.uuid), { preserveScroll: true });
    };

    const knowledgeSummary = () => {
        if (!knowledgeBase) return 'No knowledge attached.';
        if (documents.length === 0) return 'Nothing added yet. Websites, sitemaps, files and pasted text all work.';
        if (failed > 0) return `${indexed} ready, ${failed} could not be read.`;

        return `${indexed} of ${documents.length} ${documents.length === 1 ? 'source' : 'sources'} ready.`;
    };

    return (
        <ClientLayout title={bot.name}>
            <Head title={`${bot.name} · Smart Bot`} />
            <div className="max-w-4xl space-y-6">
                <div>
                    <Link href={route('client.ai.chatbots.index')} className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                        <ArrowLeft className="h-4 w-4" /> Smart Bots
                    </Link>
                    <h2 className="mt-2 flex items-center gap-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        <Bot className="h-5 w-5 text-brand-500" /> {bot.name}
                    </h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        Work down the list. Each step saves on its own.
                    </p>
                </div>

                <BotSetupSteps
                    steps={[
                        { id: 'identity', title: 'Smart Bot created', done: true },
                        { id: 'profile', title: 'Business details', done: profileComplete },
                        { id: 'knowledge', title: 'Knowledgebase', done: indexed > 0 },
                        // Ticked only once the client has saved this step. answer_scope has a
                        // database default, so its presence proves nothing.
                        { id: 'behaviour', title: 'How it answers', done: !!bot.behaviour_set_at },
                    ]}
                    current={open}
                    onSelect={setOpen}
                />

                {open === 'identity' && (
                    <Panel title="Smart Bot created">
                        <form onSubmit={saveIdentity} className="max-w-xl space-y-4">
                            <Field label="Name" error={identity.errors.name}>
                                <input className={inputCls} value={identity.data.name} onChange={(e) => identity.setData('name', e.target.value)} />
                            </Field>
                            <Field label="Tone">
                                <select className={inputCls} value={identity.data.tone} onChange={(e) => identity.setData('tone', e.target.value)}>
                                    <option value="friendly">Friendly</option>
                                    <option value="professional">Professional</option>
                                    <option value="concise">Concise</option>
                                </select>
                            </Field>
                            <Field label="Anything it should always do" error={identity.errors.system_prompt}>
                                <textarea className={inputCls} rows={3} value={identity.data.system_prompt} onChange={(e) => identity.setData('system_prompt', e.target.value)} />
                            </Field>
                            <label className="flex items-center gap-2.5">
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked={identity.data.enabled}
                                    aria-label="Bot active"
                                    onClick={() => identity.setData('enabled', !identity.data.enabled)}
                                    className="relative inline-flex h-5 w-9 flex-shrink-0 items-center rounded-full transition focus:outline-none focus:ring-2 focus:ring-brand-500/30"
                                >
                                    <span className={`absolute inset-0 rounded-full transition ${identity.data.enabled ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-700'}`} />
                                    <span className={`relative inline-block h-4 w-4 transform rounded-full bg-white transition ${identity.data.enabled ? 'translate-x-4' : 'translate-x-0.5'}`} />
                                </button>
                                <span className="text-sm text-neutral-700 dark:text-neutral-300">Active — the bot may answer customers</span>
                            </label>
                            <button type="submit" disabled={identity.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                                {identity.processing ? 'Saving…' : 'Save'}
                            </button>
                        </form>
                    </Panel>
                )}

                {open === 'profile' && (
                    <Panel title="Business details">
                        {knowledgeBase ? (
                            <form onSubmit={saveProfile} className="max-w-xl space-y-4">
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                    Lets the bot answer general questions. Without these it only answers from your sources.
                                </p>
                                <Field label="Business name *" error={profile.errors.business_name}>
                                    <input className={inputCls} value={profile.data.business_name} onChange={(e) => profile.setData('business_name', e.target.value)} placeholder="The name customers know you by" />
                                </Field>
                                <Field label="What you do *" error={profile.errors.business_purpose}>
                                    <textarea className={inputCls} rows={2} value={profile.data.business_purpose} onChange={(e) => profile.setData('business_purpose', e.target.value)} placeholder="AI automation for customer support teams" />
                                </Field>
                                <Field label="Who you serve" hint="Optional." error={profile.errors.target_audience}>
                                    <textarea className={inputCls} rows={2} value={profile.data.target_audience} onChange={(e) => profile.setData('target_audience', e.target.value)} placeholder="Small support teams handling WhatsApp and live chat" />
                                </Field>
                                <button type="submit" disabled={profile.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                                    {profile.processing ? 'Saving…' : 'Save business details'}
                                </button>
                            </form>
                        ) : (
                            <p className="text-sm text-neutral-500">This bot has no knowledge attached yet.</p>
                        )}
                    </Panel>
                )}

                {open === 'knowledge' && (
                    knowledgeBase ? (
                        <div className="space-y-4">
                            <p className="text-sm text-neutral-500 dark:text-neutral-400">{knowledgeSummary()}</p>
                            <KnowledgeManager
                                embedded
                                kb={{ ...knowledgeBase, documents }}
                                kbUploadMaxKb={kbUploadMaxKb}
                                kbUploadMaxMb={kbUploadMaxMb}
                                kbAppUploadMaxMb={kbAppUploadMaxMb}
                            />
                        </div>
                    ) : (
                        <Panel title="Knowledge">
                            <p className="text-sm text-neutral-500">This bot has no knowledge attached yet.</p>
                        </Panel>
                    )
                )}

                {open === 'behaviour' && (
                    <Panel title="How it answers">
                        <form onSubmit={saveBehaviour} className="max-w-xl space-y-4">
                            <Field label="What it may answer" error={behaviour.errors.answer_scope}>
                                <select className={inputCls} value={behaviour.data.answer_scope} onChange={(e) => behaviour.setData('answer_scope', e.target.value)}>
                                    <option value="business_only">Business only</option>
                                    <option value="verified_only">Verified knowledge only</option>
                                    <option value="general">General</option>
                                </select>
                            </Field>
                            <p className="-mt-2 text-xs text-neutral-500 dark:text-neutral-400">
                                {behaviour.data.answer_scope === 'business_only' && 'Answers general questions about your business using the details in step 2, and quotes your sources for the rest.'}
                                {behaviour.data.answer_scope === 'verified_only' && 'Only answers what it can quote from your sources. Anything else goes to your team.'}
                                {behaviour.data.answer_scope === 'general' && 'Answers broadly, including questions unrelated to your business.'}
                            </p>
                            <Field label="When no answer is verified" error={behaviour.errors.fallback_mode}>
                                <select className={inputCls} value={behaviour.data.fallback_mode} onChange={(e) => behaviour.setData('fallback_mode', e.target.value)}>
                                    <option value="clarify_then_handoff">Clarify once, then offer human help</option>
                                    <option value="handoff">Offer human help straight away</option>
                                </select>
                            </Field>

                            <Field label="What to say when it cannot answer" hint="Left empty, the bot offers to fetch a team member in the customer's own language." error={behaviour.errors.fallback_reply}>
                                <textarea className={inputCls} rows={2} value={behaviour.data.fallback_reply} onChange={(e) => behaviour.setData('fallback_reply', e.target.value)} />
                            </Field>

                            <Field label="How much of your knowledge to read per answer" hint="More passages give richer answers and cost more tokens." error={behaviour.errors.max_context_chunks}>
                                <input type="number" min={1} max={20} className={`${inputCls} w-24`} value={behaviour.data.max_context_chunks} onChange={(e) => behaviour.setData('max_context_chunks', e.target.value)} />
                            </Field>

                            {/* Kept behind a disclosure, as on the old screen: these
                                two numbers change when the bot refuses to answer. */}
                            <div className="rounded-xl border border-neutral-200 dark:border-neutral-800">
                                <button type="button" onClick={() => setShowAdvanced((v) => !v)} aria-expanded={showAdvanced} className="flex w-full items-center justify-between px-3 py-2 text-left text-xs font-semibold text-neutral-600 dark:text-neutral-300">
                                    Retrieval confidence
                                    <span className="text-neutral-400">{showAdvanced ? '−' : '+'}</span>
                                </button>
                                {showAdvanced && (
                                    <div className="grid gap-3 border-t border-neutral-200 p-3 sm:grid-cols-2 dark:border-neutral-800">
                                        <Field label="Answer above" hint="Below this the bot will not state a fact." error={behaviour.errors.confidence_threshold}>
                                            <input type="number" step="0.01" min={0.1} max={1} className={inputCls} value={behaviour.data.confidence_threshold} onChange={(e) => behaviour.setData('confidence_threshold', e.target.value)} />
                                        </Field>
                                        <Field label="Clarify above" hint="Below this it offers a person instead of guessing." error={behaviour.errors.clarification_threshold}>
                                            <input type="number" step="0.01" min={0} max={0.99} className={inputCls} value={behaviour.data.clarification_threshold} onChange={(e) => behaviour.setData('clarification_threshold', e.target.value)} />
                                        </Field>
                                    </div>
                                )}
                            </div>
                            <button type="submit" disabled={behaviour.processing} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:opacity-60">
                                {behaviour.processing ? 'Saving…' : 'Save'}
                            </button>
                        </form>
                    </Panel>
                )}
            </div>
        </ClientLayout>
    );
}
