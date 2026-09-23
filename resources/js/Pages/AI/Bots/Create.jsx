import { Head, Link, useForm } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import BotSetupSteps from '@/Components/AI/BotSetupSteps';
import { ArrowLeft, Bot } from 'lucide-react';

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

export default function BotCreate() {

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        tone: 'friendly',
        system_prompt: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('client.ai.chatbots.store'));
    };

    return (
        <ClientLayout title="New Smart Bot">
            <Head title="New Smart Bot" />
            <div className="max-w-3xl space-y-6">
                <div>
                    <Link href={route('client.ai.chatbots.index')} className="inline-flex items-center gap-1.5 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                        <ArrowLeft className="h-4 w-4" /> Smart Bots
                    </Link>
                    <h2 className="mt-2 text-xl font-semibold text-neutral-900 dark:text-neutral-100">Create a Smart Bot</h2>
                    <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                        Its knowledge is created with it. You add business details and sources next, one step at a time.
                    </p>
                </div>

                {/* The same bar the bot page carries, so setup reads as one
                    journey rather than a form followed by a separate screen.
                    Later steps are not selectable until the bot exists. */}
                <BotSetupSteps
                    steps={[
                        { id: 'identity', title: 'Smart Bot', done: false },
                        { id: 'profile', title: 'Business details', done: false },
                        { id: 'knowledge', title: 'Knowledgebase', done: false },
                        { id: 'behaviour', title: 'How it answers', done: false },
                    ]}
                    current="identity"
                    onSelect={() => {}}
                />

                <form onSubmit={submit} className="space-y-6">
                    {(
                        <div className="space-y-4 rounded-2xl border border-brand-200 bg-brand-50/50 p-5 dark:border-brand-800 dark:bg-brand-900/10">
                            <div className="flex items-start gap-3">
                                <span className="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
                                    <Bot className="h-4 w-4" />
                                </span>
                                <div>
                                    <h3 className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">Identity</h3>
                                    <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">What it is called and how it speaks.</p>
                                </div>
                            </div>

                            <Field label="Name" hint="Customers never see this — it is how you tell your bots apart." error={errors.name}>
                                <input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Support concierge" autoFocus />
                            </Field>

                            <Field label="Tone" hint="How it sounds when it replies.">
                                <select className={inputCls} value={data.tone} onChange={(e) => setData('tone', e.target.value)}>
                                    <option value="friendly">Friendly</option>
                                    <option value="professional">Professional</option>
                                    <option value="concise">Concise</option>
                                </select>
                            </Field>

                            <Field label="Anything it should always do" hint="Optional. For example: always offer a booking link when someone asks about availability." error={errors.system_prompt}>
                                <textarea className={inputCls} rows={3} value={data.system_prompt} onChange={(e) => setData('system_prompt', e.target.value)} />
                            </Field>
                        </div>
                    )}

                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={processing || !data.name.trim()}
                            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {processing ? 'Creating…' : 'Create bot'}
                        </button>
                        {!data.name.trim() && <span className="text-xs text-neutral-400">Give it a name to continue.</span>}
                    </div>

                </form>
            </div>
        </ClientLayout>
    );
}
