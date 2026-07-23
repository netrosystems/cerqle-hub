import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import { Reveal } from '@/Components/Reveal';
import { useTranslation } from 'react-i18next';

function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-2 rounded-full bg-brand-500/15 text-brand-300 text-xs font-semibold px-3.5 py-1.5 border border-brand-500/25">
            <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-70 animate-pulse-ring" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-brand-500" />
            </span>
            {text}
        </span>
    );
}

const CATEGORIES = [
    { key: 'all',       labelKey: 'faq.cat_all' },
    { key: 'general',   labelKey: 'faq.cat_general' },
    { key: 'billing',   labelKey: 'faq.cat_billing' },
    { key: 'technical', labelKey: 'faq.cat_technical' },
    { key: 'security',  labelKey: 'faq.cat_security' },
];

export default function Faq({ landing = {}, canRegister }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    const [open, setOpen] = useState(null);
    const [cat, setCat] = useState('all');

    const faqs = [1, 2, 3, 4, 5].map((i) => ({
        q: s(`faq_${i}_q`),
        a: s(`faq_${i}_a`),
    })).filter((f) => f.q && f.a);

    const title = s('faq_title', 'Frequently Asked Questions');
    const subtitle = s('faq_subtitle', 'Everything you need to know about our platform.');

    return (
        <LandingLayout>
            <Head>
                <title>{t('faq.head_title', { title })}</title>
                <meta name="description" content={subtitle} />
            </Head>

            {/* ── Page hero ── */}
            <section
                className="relative overflow-hidden py-20 text-center"
                style={{ background: 'radial-gradient(ellipse 60% 60% at 50% 0%, rgba(143,95,167,0.18) 0%, transparent 70%), #281a31' }}
            >
                <div className="pointer-events-none absolute -left-20 top-0 h-72 w-72 rounded-full bg-brand-500/20 blur-3xl animate-float-slow" />
                <div className="pointer-events-none absolute -right-16 top-10 h-72 w-72 rounded-full bg-brand-600/15 blur-3xl animate-float" />
                <div
                    className="pointer-events-none absolute inset-0"
                    style={{
                        backgroundImage: `linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px)`,
                        backgroundSize: '60px 60px',
                        maskImage: 'radial-gradient(ellipse 80% 100% at 50% 0%, black 50%, transparent 100%)',
                        WebkitMaskImage: 'radial-gradient(ellipse 80% 100% at 50% 0%, black 50%, transparent 100%)',
                    }}
                />
                <div className="relative max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                    <Reveal className="flex justify-center" y={12}><Badge text={t('nav.faq')} /></Reveal>
                    <Reveal as="h1" delay={80} className="mt-4 text-4xl sm:text-5xl font-bold text-white tracking-tight">{title}</Reveal>
                    <Reveal as="p" delay={170} className="mt-4 text-lg text-neutral-300 max-w-xl mx-auto">{subtitle}</Reveal>
                </div>
            </section>

            {/* ── Category filter ── */}
            <section className="border-b border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-950 sticky top-16 z-10">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center gap-2 overflow-x-auto py-3 scrollbar-none">
                    {CATEGORIES.map((c) => (
                        <button
                            key={c.key}
                            onClick={() => setCat(c.key)}
                            className={`flex-shrink-0 rounded-full px-4 py-1.5 text-sm font-medium transition-all duration-200 ${
                                cat === c.key
                                    ? 'text-white font-bold bg-gradient-to-b from-brand-500 to-brand-600 shadow-[0_4px_14px_-2px_rgba(143,95,167,0.5)]'
                                    : 'text-neutral-500 dark:text-neutral-400 hover:text-neutral-900 dark:hover:text-neutral-200 bg-neutral-100 dark:bg-neutral-800'
                            }`}
                        >
                            {t(c.labelKey)}
                        </button>
                    ))}
                </div>
            </section>

            {/* ── FAQ list ── */}
            <section className="py-16">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                    {faqs.length === 0 ? (
                        <p className="text-center text-neutral-500 dark:text-neutral-400 py-16">{t('faq.no_items')}</p>
                    ) : (
                        <div className="space-y-3">
                            {faqs.map((faq, idx) => {
                                const isOpen = open === idx;
                                return (
                                    <Reveal
                                        key={idx}
                                        delay={idx * 70}
                                        y={16}
                                        className={`rounded-xl border bg-white dark:bg-neutral-900 overflow-hidden transition-colors duration-200 ${isOpen ? 'border-brand-400/50 shadow-lg shadow-brand-500/10' : 'border-neutral-200 dark:border-neutral-800'}`}
                                    >
                                        <button
                                            className="w-full flex items-center justify-between px-5 py-4 text-left gap-4 group"
                                            onClick={() => setOpen(isOpen ? null : idx)}
                                        >
                                            <span className={`font-medium text-sm transition-colors ${isOpen ? 'text-brand-600 dark:text-brand-400' : 'text-neutral-900 dark:text-white group-hover:text-brand-600 dark:group-hover:text-brand-400'}`}>{faq.q}</span>
                                            <span className={`flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full transition-all duration-300 ${isOpen ? 'bg-brand-500 text-white rotate-180' : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-400'}`}>
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2.4} viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </span>
                                        </button>
                                        <div className={`grid transition-all duration-300 ease-smooth ${isOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}>
                                            <div className="overflow-hidden">
                                                <p className="px-5 pb-5 text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{faq.a}</p>
                                            </div>
                                        </div>
                                    </Reveal>
                                );
                            })}
                        </div>
                    )}

                    {/* Still have questions? */}
                    <Reveal
                        className="mt-12 rounded-2xl p-8 text-center"
                        style={{ background: 'rgba(143,95,167,0.08)', border: '1px solid rgba(143,95,167,0.25)' }}
                    >
                        <h3 className="text-lg font-bold text-neutral-900 dark:text-white mb-2">{t('faq.still_have_questions')}</h3>
                        <p className="text-sm text-neutral-600 dark:text-neutral-400 mb-5">{t('faq.still_have_questions_desc')}</p>
                        <Link
                            href="/contact"
                            className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-brand-500 to-brand-600 px-6 py-2.5 text-sm font-bold text-white shadow-[0_6px_20px_-4px_rgba(143,95,167,0.5)] transition-all hover:-translate-y-0.5"
                        >
                            {t('faq.contact_support')}
                        </Link>
                    </Reveal>
                </div>
            </section>

            {/* ── CTA ── */}
            {canRegister && (
                <section className="py-16 bg-neutral-50 dark:bg-neutral-900/30">
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                        <Reveal as="h2" className="text-2xl font-bold text-neutral-900 dark:text-white">{t('faq.ready_to_try')}</Reveal>
                        <Reveal as="p" delay={80} className="mt-2 text-neutral-500 dark:text-neutral-400 text-sm">{t('faq.ready_to_try_desc')}</Reveal>
                        <Reveal delay={160}>
                            <Link
                                href={route('register')}
                                className="mt-6 group inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-brand-500 to-brand-600 px-7 py-3.5 text-base font-bold text-white shadow-[0_10px_30px_-6px_rgba(143,95,167,0.55)] transition-all duration-200 hover:-translate-y-0.5"
                            >
                                {t('welcome.get_started_free')}
                                <svg className="h-5 w-5 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth={2.2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </Link>
                        </Reveal>
                    </div>
                </section>
            )}
        </LandingLayout>
    );
}
