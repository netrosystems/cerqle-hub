import { Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { BrandMark } from '@/Components/BrandIcons';
import { Reveal } from '@/Components/Reveal';
import { useTranslation } from 'react-i18next';

function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-2 rounded-full bg-brand-500/15 text-brand-600 dark:text-brand-300 text-xs font-semibold px-3.5 py-1.5 border border-brand-500/25">
            <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-70 animate-pulse-ring" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-brand-500" />
            </span>
            {text}
        </span>
    );
}

export default function Integrations({ canRegister, landing = {} }) {
    const { t } = useTranslation();
    const appName = import.meta.env.VITE_APP_NAME || 'Cerqle';
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    const categories = [1, 2, 3, 4, 5, 6, 7].map((i) => ({
        title: s(`intcat_${i}_title`),
        items: (s(`intcat_${i}_items`) || '').split('\n').map((x) => x.trim()).filter(Boolean),
    })).filter((c) => c.title && c.items.length);

    return (
        <LandingLayout>
            <SeoHead
                title={`${s('integrations_page_title') || t('nav.integrations', { defaultValue: 'Integrations' })} — ${appName}`}
                description={s('integrations_page_subtitle')}
            />

            {/* Hero */}
            <section
                className="relative overflow-hidden"
                style={{ background: 'radial-gradient(ellipse 70% 65% at 50% 0%, rgba(143,95,167,0.18) 0%, transparent 60%), #281a31' }}
            >
                <div className="pointer-events-none absolute -left-24 top-6 h-72 w-72 rounded-full bg-brand-500/20 blur-3xl animate-float-slow" />
                <div className="pointer-events-none absolute -right-16 top-24 h-80 w-80 rounded-full bg-brand-600/15 blur-3xl animate-float" />
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-20 text-center relative">
                    <Reveal className="flex justify-center mb-6" y={12}><Badge text={s('integrations_page_badge')} /></Reveal>
                    <Reveal as="h1" delay={80} className="text-4xl sm:text-5xl font-bold tracking-tight text-white max-w-3xl mx-auto leading-tight">
                        {s('integrations_page_title')}
                    </Reveal>
                    {s('integrations_page_subtitle') && (
                        <Reveal as="p" delay={170} className="mt-6 text-lg text-neutral-300 max-w-2xl mx-auto leading-relaxed">{s('integrations_page_subtitle')}</Reveal>
                    )}
                </div>
            </section>

            {/* Categories */}
            <section className="py-24">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
                    {categories.map((cat, ci) => (
                        <div key={ci}>
                            <Reveal as="h2" className="text-xl font-bold text-neutral-900 dark:text-white tracking-tight mb-5">{cat.title}</Reveal>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {cat.items.map((item, ii) => (
                                    <Reveal
                                        key={ii}
                                        delay={(ii % 4) * 70}
                                        y={16}
                                        className="flex items-center gap-3 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-4 hover:-translate-y-1 hover:border-brand-400/40 hover:shadow-lg hover:shadow-brand-500/10 transition-all duration-300"
                                    >
                                        <BrandMark name={item} tileClassName="h-10 w-10 rounded-xl flex-shrink-0" glyphClassName="h-6 w-6" />
                                        <span className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{item}</span>
                                    </Reveal>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            {/* CTA */}
            <section className="py-20 bg-neutral-50 dark:bg-neutral-900/30 border-t border-neutral-200 dark:border-neutral-800">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                    <Reveal as="h2" className="text-3xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {t('integrations_page.cta_title', { defaultValue: 'Need a custom integration?' })}
                    </Reveal>
                    <Reveal as="p" delay={90} className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">
                        {t('integrations_page.cta_subtitle', { defaultValue: 'Use our REST API and webhooks to connect Cerqle to anything, or talk to our team.' })}
                    </Reveal>
                    <Reveal delay={180} className="mt-8 flex flex-wrap items-center justify-center gap-4">
                        {canRegister && (
                            <Link href={route('register')} className="group inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-brand-500 to-brand-600 px-7 py-3.5 text-base font-bold text-white shadow-[0_10px_30px_-6px_rgba(143,95,167,0.55)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_16px_40px_-6px_rgba(143,95,167,0.7)]">
                                {t('welcome.get_started_free', { defaultValue: 'Get Started Free' })}
                                <svg className="h-5 w-5 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth={2.2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </Link>
                        )}
                        <Link href="/contact" className="inline-flex items-center gap-2 rounded-xl border border-neutral-300 dark:border-neutral-700 px-7 py-3.5 text-base font-semibold text-neutral-700 dark:text-neutral-300 hover:border-brand-500/50 transition-all duration-200">
                            {t('nav.contact', { defaultValue: 'Contact Sales' })}
                        </Link>
                    </Reveal>
                </div>
            </section>
        </LandingLayout>
    );
}
