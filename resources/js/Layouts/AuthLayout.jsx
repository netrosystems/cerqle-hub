import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useTheme } from '@/context/ThemeContext';
import { useLocale } from '@/hooks/useLocale';
import { Sun, Moon, ShieldCheck, Zap, Users, Bot, TrendingUp } from 'lucide-react';

/**
 * Split-pane auth layout — Cerqle editorial design language.
 *
 * - Left pane (desktop only): warm cream brand panel with editorial serif
 *   copy, the same Eyebrow badge + sparkles used on the marketing landing,
 *   and an orange radial glow.
 * - Right pane: cream-toned canvas that hosts the form card.
 *
 * The form card itself lives inside `children`. The card surface itself is
 * drawn by each auth page so the page can decide its own density.
 *
 * Props:
 *   variant  – 'client' (default) | 'admin'
 *   title    – heading shown above the form card
 *   subtitle – subheading shown below the title
 *   icon     – React node shown above the title
 *   status   – success message string (green alert)
 *   error    – error message string (red alert)
 *   children – the form content
 */

const INK = '#241f1a';

const CLIENT_FEATURES = [
    { icon: Zap,        key: 'auth.feature1', fallback: 'Automate repetitive tasks and reclaim 10+ hours a week' },
    { icon: TrendingUp, key: 'auth.feature2', fallback: 'Boost reply rates by up to 3× with AI-crafted messages' },
    { icon: Bot,        key: 'auth.feature3', fallback: '24/7 chatbots that qualify leads while you sleep' },
];

const ADMIN_FEATURES = [
    { icon: ShieldCheck, key: 'auth.admin_feature1', fallback: 'Granular role-based access for every team member' },
    { icon: Users,       key: 'auth.admin_feature2', fallback: 'Manage clients, plans, and billing from one dashboard' },
    { icon: Zap,         key: 'auth.admin_feature3', fallback: 'Real-time audit logs and live queue monitoring' },
];

const SOCIAL_PROOF = [
    { value: '10,000+', labelKey: 'auth.proof_businesses' },
    { value: '50M+',    labelKey: 'auth.proof_messages_sent' },
    { value: '99.9%',   labelKey: 'auth.proof_uptime_sla' },
];

// ── Small visual primitives reused from Welcome.jsx ─────────────────────────

function Sparkle({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8L12 2z" />
        </svg>
    );
}

function Eyebrow({ children }) {
    if (!children) return null;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full border border-black/5 bg-white/80 px-3.5 py-1.5 text-xs font-semibold text-[#3a332c] shadow-sm">
            <span className="text-brand-500"><Sparkle /></span>
            {children}
        </span>
    );
}

/** Serif heading that italicises + orange-accents the trailing clause (after the
 *  last comma) or, failing that, the final word — mirroring the landing style. */
function AccentHeading({ text, className = '' }) {
    const trimmed = (text || '').trim();
    if (!trimmed) return <span className={className} />;
    const ci = trimmed.lastIndexOf(',');
    let head;
    let tail;
    if (ci !== -1 && ci < trimmed.length - 1) {
        head = trimmed.slice(0, ci + 1);
        tail = trimmed.slice(ci + 1).trim();
    } else {
        const parts = trimmed.split(' ');
        tail = parts.pop();
        head = parts.join(' ');
    }
    return (
        <span className={className}>
            {head}
            {head ? ' ' : ''}
            <span className="italic text-brand-500">{tail}</span>
        </span>
    );
}

// ── Left brand pane ─────────────────────────────────────────────────────────

function LeftPane({ variant, logoUrl }) {
    const { t } = useTranslation();
    const features = variant === 'admin' ? ADMIN_FEATURES : CLIENT_FEATURES;
    const appName = import.meta.env.VITE_APP_NAME || 'Cerqle';
    const tagline = variant === 'admin'
        ? (t('auth.admin_tagline') || 'Manage your platform with confidence')
        : (t('auth.tagline') || 'Turn conversations into revenue — on autopilot');
    const subline = variant === 'admin'
        ? (t('auth.admin_sub') || 'Full control over clients, subscriptions, integrations, and settings.')
        : (t('auth.sub') || 'Join thousands of teams using AI to automate support, nurture leads, and close deals faster.');

    return (
        <div
            className="relative hidden lg:flex lg:w-[48%] flex-col justify-between overflow-hidden p-10 text-[#241f1a]"
            style={{ background: '#faf5ec' }}
        >
            {/* Warm cream → faint-orange radial glow, same recipe as the
                landing's hero section. */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0"
                style={{
                    background:
                        'radial-gradient(ellipse 70% 55% at 75% 35%, rgba(255,118,46,0.16) 0%, rgba(255,118,46,0.06) 40%, transparent 70%), radial-gradient(ellipse 60% 45% at 15% 80%, rgba(255,118,46,0.10) 0%, transparent 65%)',
                }}
            />
            {/* Subtle dotted grid overlay — same as landing placeholder frames. */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 opacity-[0.04]"
                style={{
                    backgroundImage:
                        'radial-gradient(circle, #241f1a 1px, transparent 1px)',
                    backgroundSize: '24px 24px',
                }}
            />

            {/* Logo + brand name */}
            <div className="relative flex items-center gap-3">
                <img src={logoUrl || '/cerqle-logo-purple.svg'} alt={appName} className="h-9 w-auto max-w-[220px] object-contain" />
                {variant === 'admin' && (
                    <span className="inline-flex items-center gap-1 rounded-full bg-brand-500/10 px-2 py-0.5 text-xs font-semibold text-brand-700">
                        <ShieldCheck className="h-3 w-3" />
                        {t('nav.admin')}
                    </span>
                )}
            </div>

            {/* Middle copy */}
            <div className="relative space-y-7">
                <div>
                    <h2 className="font-display text-[2.6rem] font-medium leading-[1.05] tracking-tight text-[#241f1a]">
                        <AccentHeading text={tagline} />
                    </h2>
                    <p className="mt-4 max-w-md text-base leading-relaxed text-[#6f6660]">
                        {subline}
                    </p>
                </div>

                <ul className="space-y-3">
                    {features.map(({ icon: Icon, key, fallback }) => (
                        <li key={key} className="flex items-start gap-3">
                            <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 transition-colors">
                                <Icon className="h-4 w-4" />
                            </span>
                            <span className="text-[15px] leading-relaxed text-[#3a332c]">
                                {t(key) || fallback}
                            </span>
                        </li>
                    ))}
                </ul>

                {variant !== 'admin' && (
                    <div className="grid grid-cols-3 gap-4 border-t border-black/[0.06] pt-5">
                        {SOCIAL_PROOF.map(({ value, labelKey }) => (
                            <div key={labelKey}>
                                <p className="font-display text-2xl font-semibold text-[#241f1a]">{value}</p>
                                <p className="mt-1 text-xs text-[#8a817a]">{t(labelKey)}</p>
                            </div>
                        ))}
                    </div>
                )}

            </div>

            {/* Footer */}
            <p className="relative text-xs text-[#a99a86]">
                &copy; 2026 Cerqle.ai (A Celimar Company). All rights reserved.
            </p>
        </div>
    );
}

// ── Top-right utilities (locale + theme) ─────────────────────────────────────

function ThemeToggle() {
    const { t } = useTranslation();
    const { theme, setTheme } = useTheme();
    return (
        <button
            type="button"
            onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
            className="inline-flex h-9 w-9 items-center justify-center rounded-full text-[#57504a] hover:bg-black/[0.04] hover:text-[#241f1a] transition"
            aria-label={t('topbar.switch_theme')}
        >
            {theme === 'dark'
                ? <Sun className="h-4 w-4" />
                : <Moon className="h-4 w-4" />}
        </button>
    );
}

function LocaleToggle() {
    const { locale, locales, setLocale } = useLocale();
    if (locales.length <= 1) return null;

    return (
        <div className="relative">
            <select
                value={locale}
                onChange={(e) => setLocale(e.target.value)}
                className="h-9 rounded-full border border-black/[0.08] bg-white/70 px-3 text-xs font-medium text-[#57504a] focus:outline-none focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500/40"
            >
                {locales.map((l) => (
                    <option key={l.code} value={l.code}>
                        {l.native_name || l.name || l.code.toUpperCase()}
                    </option>
                ))}
            </select>
        </div>
    );
}

// ── Public layout ───────────────────────────────────────────────────────────

export default function AuthLayout({
    variant = 'client',
    title,
    subtitle,
    icon,
    status,
    error,
    children,
}) {
    const { t } = useTranslation();
    const { branding } = usePage().props;
    return (
        <div className="flex min-h-screen bg-[#faf5ec] text-[#241f1a]" style={{ color: INK }}>
            <LeftPane variant={variant} logoUrl={branding?.logo_url} />

            {/* Right pane — slightly deeper cream than the left so the card
                visibly separates. */}
            <div
                className="relative flex flex-1 flex-col"
                style={{
                    background:
                        'linear-gradient(180deg, #faf5ec 0%, #f6efe2 100%)',
                }}
            >
                {/* Top bar */}
                <div className="relative flex items-center justify-between px-6 py-5">
                    {/* Mobile logo */}
                    <Link href={route('home')} className="flex items-center gap-2 lg:hidden">
                        <img src={branding?.logo_url || '/cerqle-logo-purple.svg'} alt={branding?.app_name || import.meta.env.VITE_APP_NAME || 'Cerqle'} className="h-7 w-auto max-w-[170px] object-contain" />
                    </Link>
                    <span className="hidden lg:block" />
                    <div className="flex items-center gap-2">
                        <LocaleToggle />
                        <ThemeToggle />
                    </div>
                </div>

                {/* Soft brand glow upper-right, like the landing CTA band. */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-32 -right-24 h-80 w-80 rounded-full"
                    style={{ background: 'radial-gradient(circle, rgba(255,118,46,0.14) 0%, transparent 70%)' }}
                />

                {/* Centered content */}
                <div className="relative flex flex-1 items-center justify-center px-4 pb-12 pt-2">
                    <div className="w-full max-w-md">
                        {/* Optional icon above the title (page-supplied). */}
                        {icon && (
                            <div className="mb-5 flex justify-center">{icon}</div>
                        )}

                        {/* Title block — uses the same Eyebrow + display-serif
                            pattern as the landing. */}
                        {title && (
                            <div className="mb-7 text-center">
                                <Eyebrow>
                                    {variant === 'admin'
                                        ? (t('auth.admin_panel_label') || 'Admin Panel')
                                        : (t('auth.welcome_to') || 'Welcome back')}
                                </Eyebrow>
                                <h1 className="mt-4 font-display text-[2rem] font-medium leading-[1.1] tracking-tight text-[#241f1a]">
                                    <AccentHeading text={title} />
                                </h1>
                                {subtitle && (
                                    <p className="mt-3 text-sm leading-relaxed text-[#6f6660]">
                                        {subtitle}
                                    </p>
                                )}
                            </div>
                        )}

                        {/* Status / error banners — warm tones to fit the
                            cream palette. */}
                        {status && (
                            <div className="mb-4 rounded-2xl border border-brand-500/30 bg-brand-500/10 px-4 py-3 text-sm text-brand-800">
                                {status}
                            </div>
                        )}
                        {error && (
                            <div className="mb-4 rounded-2xl border border-coral-500/30 bg-coral-500/10 px-4 py-3 text-sm text-coral-800">
                                {error}
                            </div>
                        )}

                        {/* Card — cream-white with soft warm shadow. */}
                        <div className="rounded-3xl border border-black/[0.06] bg-[#fffdf9] p-6 sm:p-8 shadow-[0_24px_60px_-24px_rgba(36,31,26,0.18)]">
                            {children}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
