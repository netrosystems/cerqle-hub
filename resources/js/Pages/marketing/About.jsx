import { Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { FeatureIcon } from '@/Components/LandingIcons';
import { Reveal, useCountUp } from '@/Components/Reveal';
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

function StatItem({ value, label, delay }) {
    const [ref, display] = useCountUp(value);
    return (
        <Reveal delay={delay} y={16} className="text-center">
            <p ref={ref} className="text-3xl sm:text-4xl font-extrabold tracking-tight text-neutral-900 dark:text-white tabular-nums">{display}</p>
            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">{label}</p>
        </Reveal>
    );
}

export default function About({ canRegister, landing = {} }) {
    const { t } = useTranslation();
    const appName = import.meta.env.VITE_APP_NAME || 'Cerqle';
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    const values = [1, 2, 3, 4].map((i) => ({
        icon: s(`about_value_${i}_icon`, 'star'),
        title: s(`about_value_${i}_title`),
        desc: s(`about_value_${i}_desc`),
    })).filter((v) => v.title);

    const stats = [1, 2, 3, 4].map((i) => ({
        value: s(`about_stat_${i}_value`),
        label: s(`about_stat_${i}_label`),
    })).filter((st) => st.value);

    const storyParagraphs = (s('about_story_body') || '').split('\n').map((p) => p.trim()).filter(Boolean);

    return (
        <LandingLayout>
            <SeoHead
                title={`${s('about_title') || t('nav.about', { defaultValue: 'About' })} — ${appName}`}
                description={s('about_subtitle')}
            />

            {/* Hero */}
            <section
                className="relative overflow-hidden"
                style={{ background: 'radial-gradient(ellipse 70% 65% at 50% 0%, rgba(143,95,167,0.18) 0%, transparent 60%), #281a31' }}
            >
                <div className="pointer-events-none absolute -left-24 top-6 h-72 w-72 rounded-full bg-brand-500/20 blur-3xl animate-float-slow" />
                <div className="pointer-events-none absolute -right-16 top-24 h-80 w-80 rounded-full bg-brand-600/15 blur-3xl animate-float" />
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-24 pb-20 text-center relative">
                    <Reveal className="flex justify-center mb-6" y={12}><Badge text={s('about_badge')} /></Reveal>
                    <Reveal as="h1" delay={80} className="text-4xl sm:text-5xl font-bold tracking-tight text-white max-w-3xl mx-auto leading-tight">
                        {s('about_title')}
                    </Reveal>
                    {s('about_subtitle') && (
                        <Reveal as="p" delay={170} className="mt-6 text-lg text-neutral-300 max-w-2xl mx-auto leading-relaxed">{s('about_subtitle')}</Reveal>
                    )}
                </div>
            </section>

            {/* Stats band */}
            {stats.length > 0 && (
                <section className="border-b border-neutral-200 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-900/30">
                    <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
                            {stats.map((st, idx) => (
                                <StatItem key={idx} value={st.value} label={st.label} delay={idx * 90} />
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* Story */}
            {storyParagraphs.length > 0 && (
                <section className="py-24">
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                        <Reveal as="h2" className="text-3xl font-bold text-neutral-900 dark:text-white tracking-tight mb-6">
                            {s('about_story_title')}
                        </Reveal>
                        <div className="space-y-4">
                            {storyParagraphs.map((p, idx) => (
                                <Reveal as="p" key={idx} delay={idx * 90} className="text-lg text-neutral-600 dark:text-neutral-400 leading-relaxed">{p}</Reveal>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* Values */}
            {values.length > 0 && (
                <section className="py-24 bg-neutral-50 dark:bg-neutral-900/30">
                    <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                        <Reveal className="text-center mb-16">
                            <h2 className="text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                                {t('about_page.values_title', { defaultValue: 'What we stand for' })}
                            </h2>
                        </Reveal>
                        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                            {values.map((v, idx) => (
                                <Reveal
                                    key={idx}
                                    delay={(idx % 4) * 90}
                                    className="group rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 transition-all duration-300 hover:-translate-y-1 hover:border-brand-400/40 hover:shadow-xl hover:shadow-brand-500/10"
                                >
                                    <div className="mb-4 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-500/10 ring-1 ring-inset ring-brand-500/15 transition-all duration-300 group-hover:bg-brand-500 group-hover:shadow-lg group-hover:shadow-brand-500/30">
                                        <FeatureIcon name={v.icon} className="h-5 w-5 text-brand-600 dark:text-brand-400 transition-colors group-hover:text-white" />
                                    </div>
                                    <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-1">{v.title}</h3>
                                    <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{v.desc}</p>
                                </Reveal>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* CTA */}
            <section className="py-20">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                    <Reveal
                        className="relative overflow-hidden rounded-3xl px-8 py-16 text-center"
                        style={{ background: 'radial-gradient(ellipse 60% 80% at 50% 50%, rgba(143,95,167,0.15) 0%, transparent 70%), #281a31', border: '1px solid rgba(143,95,167,0.2)' }}
                    >
                        <div className="pointer-events-none absolute -right-10 -top-10 h-56 w-56 rounded-full bg-brand-500/20 blur-3xl animate-float" />
                        <h2 className="relative text-3xl sm:text-4xl font-bold text-white tracking-tight max-w-2xl mx-auto">
                            {s('about_cta_title')}
                        </h2>
                        {s('about_cta_subtitle') && (
                            <p className="relative mt-4 text-lg text-neutral-400 max-w-xl mx-auto">{s('about_cta_subtitle')}</p>
                        )}
                        <div className="relative mt-10 flex flex-wrap items-center justify-center gap-4">
                            {canRegister && (
                                <Link href={route('register')} className="group inline-flex items-center gap-2 rounded-xl bg-gradient-to-b from-brand-500 to-brand-600 px-7 py-3.5 text-base font-bold text-white shadow-[0_10px_30px_-6px_rgba(143,95,167,0.55)] transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_16px_40px_-6px_rgba(143,95,167,0.7)]">
                                    {t('welcome.get_started_free', { defaultValue: 'Get Started Free' })}
                                    <svg className="h-5 w-5 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth={2.2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                                </Link>
                            )}
                            <Link href="/contact" className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/8 text-white px-7 py-3.5 text-base font-semibold hover:bg-white/15 backdrop-blur-sm transition-all duration-200">
                                {t('nav.contact', { defaultValue: 'Contact' })}
                            </Link>
                        </div>
                    </Reveal>
                </div>
            </section>
        </LandingLayout>
    );
}
