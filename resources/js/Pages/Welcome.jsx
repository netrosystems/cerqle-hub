import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import SeoHead from '@/Components/SeoHead';
import { Reveal } from '@/Components/Reveal';
import { FeatureIcon } from '@/Components/LandingIcons';
import CookieConsent from '@/Components/CookieConsent';
import { useTranslation } from 'react-i18next';

const PLUM = '#3E2A49';

function ArrowUpRight({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M7 17L17 7M8 7h9v9" />
        </svg>
    );
}

function MenuIcon({ open }) {
    return (
        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={1.8} viewBox="0 0 24 24">
            <path strokeLinecap="round" d={open ? 'M6 18L18 6M6 6l12 12' : 'M4 7h16M4 12h16M4 17h16'} />
        </svg>
    );
}

function Badge({ children, light = false }) {
    if (!children) return null;
    return (
        <span className={`inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold ${light ? 'border-white/15 bg-white/10 text-white' : 'border-brand-200 bg-white text-brand-700 shadow-sm'}`}>
            <span className={`h-2 w-2 rounded-full ${light ? 'bg-brand-300' : 'bg-brand-500'}`} />
            {children}
        </span>
    );
}

function PrimaryButton({ href, children, className = '' }) {
    return (
        <Link href={href} className={`group inline-flex items-center justify-center gap-2 rounded-full bg-brand-500 px-6 py-3 text-sm font-semibold text-white shadow-[0_14px_34px_-14px_rgba(143,95,167,0.9)] transition-all hover:-translate-y-0.5 hover:bg-brand-600 ${className}`}>
            {children}
            <ArrowUpRight className="h-4 w-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
        </Link>
    );
}

function SecondaryButton({ href, children, light = false }) {
    return (
        <Link href={href} className={`inline-flex items-center justify-center rounded-full border px-6 py-3 text-sm font-semibold transition-all hover:-translate-y-0.5 ${light ? 'border-white/20 text-white hover:bg-white/10' : 'border-brand-200 bg-white text-brand-900 hover:border-brand-400 hover:bg-brand-50'}`}>
            {children}
        </Link>
    );
}

function ChannelGlyph({ name, className = 'h-5 w-5' }) {
    const glyphs = {
        whatsapp: <path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607z" />,
        messenger: <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.654V24l4.088-2.242c1.092.301 2.246.464 3.443.464 6.627 0 12-4.975 12-11.111C24 4.974 18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26L10.732 8.1l3.131 3.259L19.752 8.1l-6.561 6.863z" />,
        instagram: <path d="M12 2.16c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85C2.38 3.92 3.9 2.38 7.15 2.23 8.42 2.17 8.8 2.16 12 2.16z" />,
        sms: <path d="M8 10.5h8M8 14h5m-9 6.5l1.5-3A8.38 8.38 0 013 11.5C3 6.81 7.03 3 12 3s9 3.81 9 8.5-4.03 8.5-9 8.5a9.7 9.7 0 01-3.2-.54L4 20.5z" />,
        email: <path d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />,
        chat: <path d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />,
    };
    const filled = ['whatsapp', 'messenger', 'instagram'].includes(name);

    return (
        <svg className={className} viewBox="0 0 24 24" fill={filled ? 'currentColor' : 'none'} stroke={filled ? 'none' : 'currentColor'} strokeWidth={filled ? 0 : 1.7} strokeLinecap="round" strokeLinejoin="round">
            {glyphs[name] || glyphs.chat}
        </svg>
    );
}

function Header({ auth, landing }) {
    const { t } = useTranslation();
    const { branding } = usePage().props;
    const [open, setOpen] = useState(false);
    const nav = [
        { label: 'Products', href: '/products' },
        { label: 'Channels', href: '/channels' },
        { label: 'Solutions', href: '/solutions' },
        { label: 'Resources', href: '/resources' },
        { label: 'Partners', href: '/partners' },
        { label: t('nav.pricing', { defaultValue: 'Pricing' }), href: '/pricing' },
    ];
    const getStarted = landing['landing.getstarted_label'] || t('welcome.get_started_free', { defaultValue: 'Get started' });

    return (
        <header className="fixed inset-x-0 top-0 z-50 border-b border-brand-100/80 bg-white/85 backdrop-blur-xl">
            <div className="mx-auto flex h-16 max-w-7xl items-center justify-between gap-6 px-4 sm:px-6 lg:px-8">
                <Link href={route('home')} className="flex items-center">
                    <img src={branding?.logo_url || '/cerqle-logo.svg'} alt={branding?.app_name || 'Cerqle'} className="h-9 w-auto max-w-[230px] object-contain" />
                </Link>
                <nav className="hidden items-center gap-1 rounded-full border border-brand-100 bg-brand-50/60 p-1 md:flex">
                    {nav.map((item) => (
                        <Link key={item.href} href={item.href} className="rounded-full px-3.5 py-2 text-sm font-medium text-secondary-600 transition-colors hover:bg-white hover:text-brand-900">
                            {item.label}
                        </Link>
                    ))}
                </nav>
                <div className="flex items-center gap-2">
                    {auth?.user ? (
                        <PrimaryButton href={route('client.dashboard')} className="hidden px-5 py-2.5 sm:inline-flex">{t('nav.dashboard', { defaultValue: 'Dashboard' })}</PrimaryButton>
                    ) : (
                        <>
                            <Link href={route('login')} className="hidden px-3.5 py-2 text-sm font-semibold text-secondary-600 transition-colors hover:text-brand-900 sm:inline-flex">
                                {landing['landing.signin_label'] || t('nav.sign_in', { defaultValue: 'Log in' })}
                            </Link>
                            <PrimaryButton href={route('register')} className="hidden px-5 py-2.5 sm:inline-flex">{getStarted}</PrimaryButton>
                        </>
                    )}
                    <button type="button" onClick={() => setOpen(!open)} className="inline-flex h-10 w-10 items-center justify-center rounded-full text-brand-900 md:hidden" aria-label="Menu">
                        <MenuIcon open={open} />
                    </button>
                </div>
            </div>
            {open && (
                <div className="border-t border-brand-100 bg-white px-4 py-4 shadow-lg md:hidden">
                    {nav.map((item) => (
                        <Link key={item.href} href={item.href} onClick={() => setOpen(false)} className="block rounded-xl px-3 py-2.5 text-sm font-medium text-secondary-700">
                            {item.label}
                        </Link>
                    ))}
                    <div className="mt-2 flex flex-col gap-2 border-t border-brand-100 pt-3">
                        <Link href={route('login')} className="rounded-xl px-3 py-2.5 text-sm font-medium text-secondary-700">{landing['landing.signin_label'] || 'Log in'}</Link>
                        <PrimaryButton href={route('register')} className="w-full">{getStarted}</PrimaryButton>
                    </div>
                </div>
            )}
        </header>
    );
}

function ProductPreview() {
    const conversations = [
        ['Messenger', 'New lead asking about pricing', '2m'],
        ['WhatsApp', 'Order update requested', '8m'],
        ['Instagram', 'Campaign reply queued', '15m'],
    ];
    const channels = ['chat', 'whatsapp', 'messenger', 'instagram', 'email'];

    return (
        <div className="relative mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <Reveal delay={360} className="relative overflow-hidden rounded-[2rem] border border-brand-100 bg-white p-2 shadow-[0_28px_90px_-42px_rgba(62,42,73,0.65)]">
                <div className="grid min-h-[25rem] overflow-hidden rounded-[1.5rem] border border-brand-100 bg-[#fbf9fd] lg:grid-cols-[17rem_1fr_20rem]">
                    <aside className="hidden border-r border-brand-100 bg-white p-5 lg:block">
                        <div className="mb-6 flex items-center justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-500">Inbox</p>
                                <p className="mt-1 text-lg font-semibold text-brand-900">All channels</p>
                            </div>
                            <span className="rounded-full bg-brand-100 px-2.5 py-1 text-xs font-semibold text-brand-700">42</span>
                        </div>
                        <div className="space-y-3">
                            {conversations.map(([channel, title, time], index) => (
                                <div key={title} className={`rounded-2xl border p-3 ${index === 0 ? 'border-brand-200 bg-brand-50' : 'border-transparent bg-white'}`}>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-xs font-semibold text-brand-700">{channel}</span>
                                        <span className="text-[11px] text-secondary-400">{time}</span>
                                    </div>
                                    <p className="mt-1 text-sm font-medium text-secondary-900">{title}</p>
                                </div>
                            ))}
                        </div>
                    </aside>
                    <div className="p-4 sm:p-6">
                        <div className="rounded-3xl border border-brand-100 bg-white p-4 shadow-sm">
                            <div className="flex items-center justify-between gap-4 border-b border-brand-100 pb-4">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-500">AI workspace</p>
                                    <h3 className="mt-1 text-xl font-semibold text-brand-900">Customer conversation</h3>
                                </div>
                                <div className="flex -space-x-2">
                                    {channels.slice(0, 4).map((channel) => (
                                        <span key={channel} className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white bg-brand-50 text-brand-600">
                                            <ChannelGlyph name={channel} />
                                        </span>
                                    ))}
                                </div>
                            </div>
                            <div className="mt-6 space-y-4">
                                <div className="max-w-[75%] rounded-2xl rounded-tl-md bg-brand-50 px-4 py-3 text-sm text-secondary-800">
                                    Hi, can I confirm delivery and speak with someone about upgrading?
                                </div>
                                <div className="ml-auto max-w-[78%] rounded-2xl rounded-tr-md bg-brand-500 px-4 py-3 text-sm text-white">
                                    Absolutely. Your order is on track, and I can show the upgrade options that fit your workspace.
                                </div>
                                <div className="grid gap-3 pt-3 sm:grid-cols-3">
                                    {['Intent: billing', 'Priority: warm lead', 'Next: human handoff'].map((item) => (
                                        <span key={item} className="rounded-2xl border border-brand-100 bg-[#fbf9fd] px-3 py-2 text-xs font-semibold text-brand-800">
                                            {item}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                    <aside className="border-t border-brand-100 bg-white p-5 lg:border-l lg:border-t-0">
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-brand-500">Automation</p>
                        <div className="mt-5 space-y-4">
                            {['Capture lead', 'Answer with AI', 'Route to sales'].map((step, index) => (
                                <div key={step} className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">{index + 1}</span>
                                    <div className="h-2 flex-1 rounded-full bg-brand-100">
                                        <div className="h-full rounded-full bg-brand-500" style={{ width: `${86 - index * 18}%` }} />
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="mt-8 rounded-3xl bg-brand-900 p-5 text-white">
                            <p className="text-3xl font-bold">68%</p>
                            <p className="mt-2 text-sm text-white/70">of repetitive questions automated this week.</p>
                        </div>
                    </aside>
                </div>
            </Reveal>
        </div>
    );
}

function Hero({ landing, auth, canRegister }) {
    const { t } = useTranslation();
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    if (s('hero_enabled') !== '1') return null;

    return (
        <section className="relative isolate overflow-hidden bg-[linear-gradient(180deg,#ffffff_0%,#fbf9fd_55%,#f5eff8_100%)] pt-28">
            <div className="absolute inset-x-0 top-0 -z-10 h-[34rem] bg-[radial-gradient(circle_at_50%_0%,rgba(143,95,167,0.18),transparent_55%)]" />
            <div className="mx-auto max-w-5xl px-4 pb-12 pt-8 text-center sm:px-6 sm:pt-16 lg:px-8">
                <Reveal className="flex justify-center" y={12}>
                    <Badge>{s('hero_badge')}</Badge>
                </Reveal>
                <Reveal as="h1" delay={80} className="mx-auto mt-7 max-w-4xl text-4xl font-bold leading-[1.05] tracking-normal text-brand-900 sm:text-6xl lg:text-7xl">
                    {s('hero_title')}
                </Reveal>
                <Reveal as="p" delay={160} className="mx-auto mt-6 max-w-2xl text-base leading-8 text-secondary-600 sm:text-lg">
                    {s('hero_subtitle')}
                </Reveal>
                <Reveal delay={240} className="mt-8 flex flex-wrap items-center justify-center gap-3">
                    {auth?.user ? (
                        <PrimaryButton href={route('client.dashboard')}>{t('welcome.goToDashboard', { defaultValue: 'Go to dashboard' })}</PrimaryButton>
                    ) : (
                        <>
                            {canRegister && s('hero_cta_primary') && <PrimaryButton href={route('register')}>{s('hero_cta_primary')}</PrimaryButton>}
                            {s('hero_cta_secondary') && <SecondaryButton href="/#features">{s('hero_cta_secondary')}</SecondaryButton>}
                        </>
                    )}
                </Reveal>
            </div>
            <ProductPreview />
        </section>
    );
}

function LogoCloud({ landing }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const brands = [1, 2, 3, 4, 5, 6].map((i) => s(`stats_${i}_label`)).filter(Boolean);
    if (s('stats_enabled') !== '1' || !brands.length) return null;

    return (
        <section className="border-y border-brand-100 bg-white py-10">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <p className="text-center text-xs font-semibold uppercase tracking-[0.2em] text-secondary-400">{s('stats_heading', 'Trusted by teams building better conversations')}</p>
                <div className="mt-7 flex flex-wrap items-center justify-center gap-x-10 gap-y-5">
                    {brands.map((brand) => (
                        <span key={brand} className="text-lg font-semibold text-secondary-300 transition-colors hover:text-brand-600">{brand}</span>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Overview({ landing }) {
    const { t } = useTranslation();
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;

    return (
        <section className="bg-[#fbf9fd] py-20 sm:py-28">
            <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-[0.95fr_1.05fr] lg:px-8">
                <Reveal>
                    <Badge>{s('why_badge', 'Why Cerqle')}</Badge>
                    <h2 className="mt-6 max-w-xl text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">
                        {s('why_title', 'One workspace for every customer conversation')}
                    </h2>
                    <p className="mt-5 max-w-xl text-base leading-8 text-secondary-600">
                        {s('solution_desc', s('why_subtitle', 'Unify inboxes, automate repetitive replies, and give every team a clearer way to support customers.'))}
                    </p>
                    <div className="mt-8">
                        <PrimaryButton href="/#features">{t('welcome.learn_more', { defaultValue: 'Learn more' })}</PrimaryButton>
                    </div>
                </Reveal>
                <Reveal delay={120} className="grid gap-4 sm:grid-cols-2">
                    {[
                        ['AI replies', 'Instant answers trained from your knowledge base.', 'zap'],
                        ['Shared inbox', 'Every channel routed into one clear queue.', 'message-square'],
                        ['Automation', 'No-code flows for handoff, routing, and follow-up.', 'share-2'],
                        ['Analytics', 'Track response speed, volume, and outcomes.', 'bar-chart-2'],
                    ].map(([title, desc, icon], index) => (
                        <div key={title} className={`${index === 0 ? 'bg-brand-900 text-white' : 'bg-white text-brand-900'} rounded-3xl border border-brand-100 p-6 shadow-sm`}>
                            <span className={`flex h-11 w-11 items-center justify-center rounded-2xl ${index === 0 ? 'bg-white/15 text-white' : 'bg-brand-50 text-brand-600'}`}>
                                <FeatureIcon name={icon} className="h-5 w-5" />
                            </span>
                            <h3 className="mt-5 text-lg font-semibold">{title}</h3>
                            <p className={`mt-2 text-sm leading-7 ${index === 0 ? 'text-white/70' : 'text-secondary-600'}`}>{desc}</p>
                        </div>
                    ))}
                </Reveal>
            </div>
        </section>
    );
}

function Channels({ landing }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const channels = [1, 2, 3, 4, 5].map((i) => ({
        key: s(`channel_${i}_key`, 'chat'),
        title: s(`channel_${i}_title`),
        desc: s(`channel_${i}_desc`),
    })).filter((channel) => channel.title);
    if (s('channels_enabled') !== '1' || !channels.length) return null;

    return (
        <section className="bg-white py-20 sm:py-28">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-3xl text-center">
                    <Badge>{s('channels_badge', 'Omnichannel')}</Badge>
                    <h2 className="mt-6 text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">{s('channels_title')}</h2>
                    <p className="mt-4 text-base leading-8 text-secondary-600">{s('channels_subtitle')}</p>
                </Reveal>
                <div className="mt-14 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {channels.map((channel, index) => (
                        <Reveal key={channel.title} delay={(index % 5) * 60} className="group rounded-3xl border border-brand-100 bg-[#fbf9fd] p-6 transition-all hover:-translate-y-1 hover:border-brand-300 hover:bg-white hover:shadow-xl hover:shadow-brand-900/5">
                            <span className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-100 text-brand-700 transition-colors group-hover:bg-brand-500 group-hover:text-white">
                                <ChannelGlyph name={channel.key} className="h-6 w-6" />
                            </span>
                            <h3 className="text-base font-semibold text-brand-900">{channel.title}</h3>
                            <p className="mt-2 text-sm leading-7 text-secondary-600">{channel.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function UseCases({ landing }) {
    const { t } = useTranslation();
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const cards = [1, 2, 3].map((i) => ({
        icon: s(`why_${i}_icon`, 'star'),
        title: s(`why_${i}_title`),
        desc: s(`why_${i}_desc`),
    })).filter((card) => card.title);
    if (s('why_enabled') !== '1' || !cards.length) return null;

    return (
        <section className="bg-[#fbf9fd] py-20 sm:py-28">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-3xl text-center">
                    <Badge>{t('nav.use_cases', { defaultValue: 'Use cases' })}</Badge>
                    <h2 className="mt-6 text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">Built for support, marketing, and sales teams. Together.</h2>
                </Reveal>
                <div className="mt-14 grid gap-5 lg:grid-cols-3">
                    {cards.map((card, index) => (
                        <Reveal key={card.title} delay={index * 80} className={`rounded-[1.75rem] border p-7 ${index === 1 ? 'border-brand-500 bg-brand-500 text-white shadow-xl shadow-brand-500/20' : 'border-brand-100 bg-white text-brand-900 shadow-sm'}`}>
                            <span className={`flex h-12 w-12 items-center justify-center rounded-2xl ${index === 1 ? 'bg-white/20 text-white' : 'bg-brand-50 text-brand-600'}`}>
                                <FeatureIcon name={card.icon} className="h-6 w-6" />
                            </span>
                            <h3 className="mt-6 text-xl font-semibold">{card.title}</h3>
                            <p className={`mt-3 text-sm leading-7 ${index === 1 ? 'text-white/80' : 'text-secondary-600'}`}>{card.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Process({ landing }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const steps = [1, 2, 3].map((i) => ({ title: s(`step_${i}_title`), desc: s(`step_${i}_desc`) })).filter((step) => step.title);
    if (s('howitworks_enabled') !== '1' || !steps.length) return null;

    return (
        <section className="bg-white py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-3xl text-center">
                    <Badge>{s('howitworks_badge', 'Process')}</Badge>
                    <h2 className="mt-6 text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">{s('howitworks_title', 'Launch automation in three steps')}</h2>
                    <p className="mt-4 text-base leading-8 text-secondary-600">{s('howitworks_subtitle')}</p>
                </Reveal>
                <div className="mt-14 grid gap-5 md:grid-cols-3">
                    {steps.map((step, index) => (
                        <Reveal key={step.title} delay={index * 90} className="rounded-[1.75rem] border border-brand-100 bg-[#fbf9fd] p-7">
                            <span className="text-5xl font-bold text-brand-200">0{index + 1}</span>
                            <h3 className="mt-5 text-lg font-semibold text-brand-900">{step.title}</h3>
                            <p className="mt-2 text-sm leading-7 text-secondary-600">{step.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Features({ landing }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const features = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => ({
        icon: s(`feature_${i}_icon`, 'zap'),
        title: s(`feature_${i}_title`),
        desc: s(`feature_${i}_desc`),
    })).filter((feature) => feature.title);
    if (s('features_enabled') !== '1' || !features.length) return null;

    return (
        <section id="features" className="scroll-mt-20 bg-[#fbf9fd] py-20 sm:py-28">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-3xl text-center">
                    <Badge>{s('features_badge', 'Features')}</Badge>
                    <h2 className="mt-6 text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">{s('features_title')}</h2>
                    <p className="mt-4 text-base leading-8 text-secondary-600">{s('features_subtitle')}</p>
                </Reveal>
                <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((feature, index) => (
                        <Reveal key={feature.title} delay={(index % 3) * 70} className="group rounded-[1.75rem] border border-brand-100 bg-white p-7 shadow-sm transition-all hover:-translate-y-1 hover:border-brand-300 hover:shadow-xl hover:shadow-brand-900/5">
                            <span className="mb-5 flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-600 transition-colors group-hover:bg-brand-500 group-hover:text-white">
                                <FeatureIcon name={feature.icon} className="h-6 w-6" />
                            </span>
                            <h3 className="text-lg font-semibold text-brand-900">{feature.title}</h3>
                            <p className="mt-2 text-sm leading-7 text-secondary-600">{feature.desc}</p>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Testimonials({ landing }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const items = [1, 2, 3, 4, 5, 6].map((i) => ({
        name: s(`testimonial_${i}_name`),
        role: s(`testimonial_${i}_role`),
        text: s(`testimonial_${i}_text`),
    })).filter((item) => item.name && item.text);
    if (s('testimonials_enabled') !== '1' || !items.length) return null;

    return (
        <section className="bg-white py-20 sm:py-28">
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <Reveal className="mx-auto max-w-3xl text-center">
                    <Badge>{s('testimonials_badge', 'Loved by teams')}</Badge>
                    <h2 className="mt-6 text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">{s('testimonials_title')}</h2>
                </Reveal>
                <div className="mt-14 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map((item, index) => (
                        <Reveal key={`${item.name}-${index}`} delay={(index % 3) * 70} className="rounded-[1.75rem] border border-brand-100 bg-[#fbf9fd] p-7">
                            <div className="flex gap-1 text-brand-500">
                                {[0, 1, 2, 3, 4].map((star) => <span key={star}>*</span>)}
                            </div>
                            <p className="mt-4 text-sm leading-7 text-secondary-700">"{item.text}"</p>
                            <div className="mt-6 flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-sm font-bold text-brand-700">{item.name.charAt(0)}</span>
                                <div>
                                    <p className="text-sm font-semibold text-brand-900">{item.name}</p>
                                    <p className="text-xs text-secondary-500">{item.role}</p>
                                </div>
                            </div>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

function Faq({ landing }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const [open, setOpen] = useState(0);
    const faqs = [1, 2, 3, 4, 5].map((i) => ({ q: s(`faq_${i}_q`), a: s(`faq_${i}_a`) })).filter((faq) => faq.q && faq.a);
    if (s('faq_enabled') !== '1' || !faqs.length) return null;

    return (
        <section className="bg-[#fbf9fd] py-20 sm:py-28">
            <div className="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <Reveal className="text-center">
                    <Badge>{s('faq_badge', 'FAQ')}</Badge>
                    <h2 className="mt-6 text-3xl font-bold leading-tight text-brand-900 sm:text-5xl">{s('faq_title')}</h2>
                </Reveal>
                <div className="mt-12 space-y-3">
                    {faqs.map((faq, index) => {
                        const isOpen = open === index;
                        return (
                            <Reveal key={faq.q} delay={index * 50} className={`rounded-2xl border bg-white transition-colors ${isOpen ? 'border-brand-400/60' : 'border-brand-100'}`}>
                                <button className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left" onClick={() => setOpen(isOpen ? -1 : index)}>
                                    <span className={`text-base font-semibold ${isOpen ? 'text-brand-600' : 'text-brand-900'}`}>{faq.q}</span>
                                    <span className={`flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full transition-all ${isOpen ? 'rotate-45 bg-brand-500 text-white' : 'bg-brand-50 text-secondary-500'}`}>
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2.2} viewBox="0 0 24 24"><path strokeLinecap="round" d="M12 5v14M5 12h14" /></svg>
                                    </span>
                                </button>
                                <div className={`grid transition-all duration-300 ease-smooth ${isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                                    <div className="overflow-hidden">
                                        <p className="px-6 pb-5 text-sm leading-7 text-secondary-600">{faq.a}</p>
                                    </div>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

function CtaBand({ landing, auth, canRegister }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    if (s('cta_enabled') !== '1') return null;

    return (
        <section className="bg-[#fbf9fd] px-4 pb-24 sm:px-6 lg:px-8">
            <Reveal className="relative mx-auto max-w-7xl overflow-hidden rounded-[2rem] bg-brand-900 px-6 py-16 text-center shadow-[0_24px_70px_-40px_rgba(62,42,73,0.9)] sm:py-20">
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_0%,rgba(143,95,167,0.42),transparent_55%)]" />
                <Badge light>{s('cta_badge', 'Ready when you are')}</Badge>
                <h2 className="relative mx-auto mt-6 max-w-3xl text-3xl font-bold leading-tight text-white sm:text-5xl">{s('cta_title')}</h2>
                {s('cta_subtitle') && <p className="relative mx-auto mt-5 max-w-xl text-base leading-8 text-white/70">{s('cta_subtitle')}</p>}
                <div className="relative mt-9 flex flex-wrap items-center justify-center gap-3">
                    {auth?.user ? (
                        <PrimaryButton href={route('client.dashboard')}>{s('cta_primary', 'Open dashboard')}</PrimaryButton>
                    ) : (
                        <>
                            {canRegister && <PrimaryButton href={route('register')}>{s('cta_primary', 'Get started')}</PrimaryButton>}
                            <SecondaryButton href="/contact" light>{s('cta_secondary', 'Book a demo')}</SecondaryButton>
                        </>
                    )}
                </div>
            </Reveal>
        </section>
    );
}

function Footer() {
    const { t } = useTranslation();
    const year = new Date().getFullYear();
    const columns = [
        { title: t('landing_page_admin.footer_product', { defaultValue: 'Product' }), links: [
            { label: 'Products', href: '/products' },
            { label: 'Channels', href: '/channels' },
            { label: 'Solutions', href: '/solutions' },
            { label: 'Resources', href: '/resources' },
            { label: 'Partners', href: '/partners' },
            { label: t('nav.pricing', { defaultValue: 'Pricing' }), href: '/pricing' },
        ] },
        { title: t('landing_page_admin.footer_company', { defaultValue: 'Company' }), links: [
            { label: t('landing_page_admin.footer_about', { defaultValue: 'About' }), href: '/about-us' },
            { label: 'Careers', href: '/careers' },
            { label: 'Offices', href: '/offices' },
            { label: t('nav.contact', { defaultValue: 'Contact' }), href: '/contact' },
        ] },
        { title: t('landing_page_admin.footer_legal', { defaultValue: 'Legal' }), links: [
            { label: t('landing_page_admin.footer_privacy', { defaultValue: 'Privacy Policy' }), href: '/privacy' },
            { label: 'Policies', href: '/policies' },
            { label: t('landing_page_admin.footer_terms', { defaultValue: 'Terms of Service' }), href: '/p/terms' },
        ] },
    ];

    return (
        <footer className="bg-[#281a31] py-14 text-white">
            <div className="mx-auto grid max-w-7xl gap-10 px-4 sm:grid-cols-2 sm:px-6 lg:grid-cols-4 lg:px-8">
                <div>
                    <img src="/cerqle-logo-white.svg" alt="Cerqle" className="h-7 w-auto" />
                    <p className="mt-4 max-w-xs text-sm leading-7 text-white/55">{t('landing.footer_tagline', { defaultValue: 'Omnichannel customer support, automated with AI.' })}</p>
                    <p className="mt-8 text-xs text-white/40">&copy; {year} {t('nav.all_rights_reserved', { defaultValue: 'Cerqle.ai. All rights reserved.' })}</p>
                </div>
                {columns.map((column) => (
                    <div key={column.title}>
                        <h4 className="text-xs font-semibold uppercase tracking-[0.18em] text-white/40">{column.title}</h4>
                        <ul className="mt-4 space-y-3">
                            {column.links.map((link) => (
                                <li key={link.href}><Link href={link.href} className="text-sm text-white/60 transition-colors hover:text-white">{link.label}</Link></li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </footer>
    );
}

export default function Welcome({ auth, canRegister, landing = {} }) {
    const s = (key, fallback = '') => landing[`landing.${key}`] ?? fallback;
    const appName = import.meta.env.VITE_APP_NAME || 'Cerqle';
    const metaTitle = s('seo_title') || s('hero_title') || appName;
    const metaDesc = s('seo_description') || s('hero_subtitle') || '';
    const faqs = [1, 2, 3, 4, 5].map((i) => ({ q: s(`faq_${i}_q`), a: s(`faq_${i}_a`) })).filter((faq) => faq.q && faq.a);
    const jsonLd = [
        { '@context': 'https://schema.org', '@type': 'Organization', name: appName, description: metaDesc },
        { '@context': 'https://schema.org', '@type': 'SoftwareApplication', name: appName, applicationCategory: 'BusinessApplication', operatingSystem: 'Web', description: metaDesc, offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' } },
    ];
    if (faqs.length) {
        jsonLd.push({ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: faqs.map((faq) => ({ '@type': 'Question', name: faq.q, acceptedAnswer: { '@type': 'Answer', text: faq.a } })) });
    }

    return (
        <div className="min-h-screen bg-[#fbf9fd] font-sans text-brand-900" style={{ color: PLUM }}>
            <SeoHead title={metaTitle} description={metaDesc} keywords={s('seo_keywords')} image={s('seo_og_image') || undefined} jsonLd={jsonLd} />
            <Header auth={auth} landing={landing} />
            <main>
                <Hero landing={landing} auth={auth} canRegister={canRegister} />
                <LogoCloud landing={landing} />
                <Overview landing={landing} />
                <Channels landing={landing} />
                <UseCases landing={landing} />
                <Process landing={landing} />
                <Features landing={landing} />
                <Testimonials landing={landing} />
                <Faq landing={landing} />
                <CtaBand landing={landing} auth={auth} canRegister={canRegister} />
            </main>
            <Footer />
            <CookieConsent />
        </div>
    );
}
