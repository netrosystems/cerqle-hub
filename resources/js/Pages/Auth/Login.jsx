import AuthLayout from '@/Layouts/AuthLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { LogIn, Sparkles, ArrowUpRight, Eye, EyeOff } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const PROVIDERS = [
    { id: 'google',    label: 'Google',    color: 'text-red-500' },
    { id: 'github',    label: 'GitHub',    color: 'text-neutral-800 dark:text-neutral-200' },
    { id: 'microsoft', label: 'Microsoft', color: 'text-blue-500' },
];

export function shouldUseFirebaseGoogle(firebase = {}, socialProviders = []) {
    return Boolean(firebase.enabled && firebase.apiKey && !socialProviders.includes('google'));
}

function GoogleIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
        </svg>
    );
}

// ── Firebase Google sign-in ─────────────────────────────────────────────────

function FirebaseGoogleButton() {
    const { t } = useTranslation();
    const { props } = usePage();
    const firebase = useMemo(() => props.firebase ?? {}, [props.firebase]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const initRef = useRef(false);

    useEffect(() => {
        if (initRef.current || !firebase.enabled || !firebase.apiKey) return;
        initRef.current = true;
        import('@/lib/firebase').then(({ initFirebase }) => {
            initFirebase({
                apiKey:     firebase.apiKey,
                authDomain: firebase.authDomain,
                projectId:  firebase.projectId,
                appId:      firebase.appId,
            });
        });
    }, [firebase]);

    if (!firebase.enabled || !firebase.apiKey) return null;

    const firebaseErrorMessage = (err) => {
        const code = err?.code ? String(err.code) : null;
        const message = err?.message ? String(err.message) : null;

        if (code === 'auth/unauthorized-domain') {
            return 'Google sign-in failed: this domain is not authorized in Firebase.';
        }

        if (code === 'auth/operation-not-allowed') {
            return 'Google sign-in failed: Google provider is not enabled in Firebase.';
        }

        if (code === 'auth/invalid-api-key' || code === 'auth/api-key-not-valid.-please-pass-a-valid-api-key.') {
            return 'Google sign-in failed: Firebase API key is invalid.';
        }

        if (code) {
            return `Google sign-in failed: ${code}`;
        }

        if (message) {
            return `Google sign-in failed: ${message}`;
        }

        return t('auth.google_signin_failed');
    };

    const handleClick = async () => {
        setError(null);
        setLoading(true);
        try {
            const { signInWithGoogle } = await import('@/lib/firebase');
            const idToken = await signInWithGoogle();
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const res = await fetch(route('auth.firebase'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ id_token: idToken }),
            });
            const data = await res.json();
            if (data.redirect) {
                window.location.href = data.redirect;
            } else {
                setError(data.message ?? t('auth.login_failed'));
            }
        } catch (err) {
            if (err?.code === 'auth/popup-closed-by-user') {
                // user dismissed — no error shown
            } else {
                setError(firebaseErrorMessage(err));
            }
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="space-y-1">
            <button
                type="button"
                onClick={handleClick}
                disabled={loading}
                className="group flex w-full items-center justify-center gap-3 rounded-full border border-black/[0.08] bg-white px-4 py-2.5 text-sm font-semibold text-[#241f1a] hover:border-brand-500/40 hover:bg-[#fffdf9] transition disabled:opacity-60 shadow-sm"
            >
                <GoogleIcon />
                {loading ? t('auth.signing_in') : t('auth.continue_with_google')}
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-black/[0.04] text-[#57504a] transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:bg-brand-500 group-hover:text-white">
                    <ArrowUpRight className="h-3.5 w-3.5" />
                </span>
            </button>
            {error && <p className="text-xs text-coral-600 text-center">{error}</p>}
        </div>
    );
}

// ── Cream-tone editorial input (overrides the default neutral styling so the
//    form matches the AuthLayout's cream palette). Keeps the underlying Input
//    component's behaviour (label / error / hint) but overrides only look. ──

function Field({ label, error, children, hint }) {
    return (
        <div className="w-full">
            {label && (
                <label className="mb-1.5 block text-sm font-medium text-[#3a332c]">
                    {label}
                </label>
            )}
            {children}
            {hint && !error && (
                <p className="mt-1.5 text-xs text-[#8a817a]">{hint}</p>
            )}
            {error && (
                <p className="mt-1.5 text-xs text-coral-600">{error}</p>
            )}
        </div>
    );
}

function CreamInput({ label, error, className = '', type, ...props }) {
    const { t } = useTranslation();
    const isPassword = type === 'password';
    const [visible, setVisible] = useState(false);
    const effectiveType = isPassword && visible ? 'text' : type;
    return (
        <Field label={label} error={error}>
            <div className={isPassword ? 'relative' : undefined}>
                <input
                    {...props}
                    type={effectiveType}
                    className={[
                        'w-full rounded-full border bg-white px-4 py-2.5 text-sm text-[#241f1a] placeholder:text-[#a99a86] transition focus:outline-none focus:ring-2',
                        error
                            ? 'border-coral-500/50 focus:border-coral-500 focus:ring-coral-500/15'
                            : 'border-black/[0.08] focus:border-brand-500/50 focus:ring-brand-500/15',
                        isPassword ? 'pr-11' : '',
                        className,
                    ].filter(Boolean).join(' ')}
                />
                {isPassword && (
                    <button
                        type="button"
                        onClick={() => setVisible((v) => !v)}
                        tabIndex={-1}
                        aria-label={
                            visible
                                ? (t('auth.toggle_password_hide') || 'Hide password')
                                : (t('auth.toggle_password_show') || 'Show password')
                        }
                        aria-pressed={visible}
                        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-[#8a817a] hover:text-[#241f1a] transition"
                    >
                        {visible
                            ? <EyeOff className="h-4 w-4" aria-hidden="true" />
                            : <Eye className="h-4 w-4" aria-hidden="true" />}
                    </button>
                )}
            </div>
        </Field>
    );
}

// ── Demo mode: warm-cream panel matching the landing's Eyebrow pattern ──────

const DEMO_ROLE_META = {
    admin:  { labelKey: 'auth.demo_role_admin',  fallback: 'Admin' },
    client: { labelKey: 'auth.demo_role_client', fallback: 'Client' },
};

function DemoAccountRow({ account }) {
    const { t } = useTranslation();
    const [loading, setLoading] = useState(false);
    const meta = DEMO_ROLE_META[account.role] ?? { labelKey: null, fallback: account.role };

    const login = () => {
        router.post(route('login'), {
            email: account.email,
            password: account.password,
            remember: true,
        }, {
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    return (
        <div className="flex items-center justify-between gap-3 px-4 py-3">
            <div className="min-w-0">
                <p className="text-sm font-semibold text-[#241f1a]">
                    {meta.labelKey ? (t(meta.labelKey) || meta.fallback) : meta.fallback}
                </p>
                <p className="truncate text-xs text-[#8a817a]">{account.email}</p>
                <p className="text-xs text-[#8a817a]">
                    {(t('auth.demo_password_label') || 'Password')}: {account.password}
                </p>
            </div>
            <button
                type="button"
                onClick={login}
                disabled={loading}
                className="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-brand-500 px-3.5 py-2 text-sm font-semibold text-white shadow-[0_4px_14px_-4px_rgba(255,118,46,0.45)] transition hover:bg-brand-600 hover:-translate-y-0.5 disabled:opacity-60"
            >
                {loading
                    ? (t('auth.signing_in') || 'Signing in…')
                    : (t('auth.demo_one_click_login') || 'One-click login')}
            </button>
        </div>
    );
}

function DemoLoginPanel({ accounts }) {
    const { t } = useTranslation();

    return (
        <div className="mb-5 rounded-2xl border border-dashed border-brand-500/30 bg-brand-500/[0.05] p-4">
            <div className="mb-3 flex items-center gap-2">
                <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-500/15 text-brand-600">
                    <Sparkles className="h-4 w-4" />
                </span>
                <div>
                    <p className="text-sm font-semibold text-[#241f1a]">
                        {t('auth.demo_mode_title') || 'Demo mode'}
                    </p>
                    <p className="text-xs text-[#8a817a]">
                        {t('auth.demo_mode_subtitle') || 'Sign in instantly with a demo account.'}
                    </p>
                </div>
            </div>
            <div className="divide-y divide-black/[0.06] rounded-2xl border border-black/[0.06] bg-[#fffdf9] overflow-hidden">
                {accounts.map((account) => (
                    <DemoAccountRow key={`${account.role}:${account.email}`} account={account} />
                ))}
            </div>
        </div>
    );
}

// Cream-toned divider that reads on the white card surface
function CreamDivider({ label }) {
    return (
        <div className="relative my-5">
            <div className="absolute inset-0 flex items-center">
                <div className="w-full border-t border-black/[0.06]" />
            </div>
            <div className="relative flex justify-center">
                <span className="bg-[#fffdf9] px-3 text-xs font-medium text-[#a99a86]">
                    {label}
                </span>
            </div>
        </div>
    );
}

// ── Page ────────────────────────────────────────────────────────────────────

export default function Login({ status, canResetPassword, socialProviders = [], demo = null }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const firebase = props.firebase ?? {};
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    const enabledProviders = PROVIDERS.filter(p => socialProviders.includes(p.id));
    // The dedicated Google OAuth client is the canonical sign-in flow. Keep
    // Firebase only as a backwards-compatible fallback for installations that
    // have not configured Google Sign-In in Super Admin yet.
    const useFirebaseGoogle = shouldUseFirebaseGoogle(firebase, socialProviders);
    const hasSocialButtons = enabledProviders.length > 0 || useFirebaseGoogle;

    return (
        <AuthLayout
            title={t('auth.log_in') || 'Sign in to your account'}
            subtitle={t('auth.login_subtitle') || 'Enter your credentials to continue'}
            status={status}
        >
            <Head title={t('auth.log_in') || 'Log in'} />

            {/* Demo mode: one-click sign-in with the seeded demo accounts */}
            {demo && demo.length > 0 && (
                <>
                    <DemoLoginPanel accounts={demo} />
                    <CreamDivider label={t('auth.demo_or_sign_in_manually') || 'or sign in manually'} />
                </>
            )}

            {/* Social / Firebase providers */}
            {hasSocialButtons && (
                <>
                    <div className="space-y-2 mb-2">
                        {useFirebaseGoogle && <FirebaseGoogleButton />}
                        {enabledProviders.map((provider) => (
                            <a
                                key={provider.id}
                                href={route('auth.social.redirect', { provider: provider.id })}
                                className="group flex w-full items-center justify-center gap-3 rounded-full border border-black/[0.08] bg-white px-4 py-2.5 text-sm font-semibold text-[#241f1a] hover:border-brand-500/40 hover:bg-[#fffdf9] transition shadow-sm"
                            >
                                {t('auth.continue_with', { provider: provider.label })}
                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-black/[0.04] text-[#57504a] transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:bg-brand-500 group-hover:text-white">
                                    <ArrowUpRight className="h-3.5 w-3.5" />
                                </span>
                            </a>
                        ))}
                    </div>
                    <CreamDivider label={t('auth.or_continue_with_email', { defaultValue: 'or continue with email' })} />
                </>
            )}

            <form onSubmit={submit} className="space-y-4">
                <CreamInput
                    id="email"
                    type="email"
                    name="email"
                    label={t('auth.email') || 'Email address'}
                    value={data.email}
                    autoComplete="username"
                    autoFocus
                    placeholder="you@company.com"
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                />

                <CreamInput
                    id="password"
                    type="password"
                    name="password"
                    label={t('auth.password') || 'Password'}
                    value={data.password}
                    autoComplete="current-password"
                    placeholder="••••••••"
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                />

                <div className="flex items-center justify-between pt-1">
                    <label className="inline-flex items-center gap-2.5 cursor-pointer select-none">
                        <input
                            id="remember"
                            name="remember"
                            type="checkbox"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                            className="h-4 w-4 rounded border-black/[0.15] text-brand-500 focus:ring-2 focus:ring-brand-500/20"
                        />
                        <span className="text-sm text-[#3a332c]">
                            {t('auth.remember_me') || 'Remember me'}
                        </span>
                    </label>
                    <div className="flex items-center gap-4 text-sm">
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-sm font-medium text-brand-600 hover:text-brand-700"
                            >
                                {t('auth.forgot_password') || 'Forgot password?'}
                            </Link>
                        )}
                        <Link
                            href={route('auth.magic-link')}
                            className="text-sm text-[#8a817a] hover:text-[#241f1a]"
                        >
                            {t('auth.magic_link')}
                        </Link>
                    </div>
                </div>

                {/* Primary CTA — matches the landing's rounded-full dark button */}
                <button
                    type="submit"
                    disabled={processing}
                    className="group mt-2 inline-flex w-full items-center justify-center gap-2 rounded-full bg-[#241f1a] px-6 py-3 text-sm font-semibold text-white transition-all duration-200 hover:bg-[#3a332c] hover:-translate-y-0.5 disabled:opacity-60 disabled:hover:translate-y-0 shadow-[0_10px_30px_-10px_rgba(36,31,26,0.45)]"
                >
                    {processing ? (
                        <>
                            <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" className="opacity-25" />
                                <path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                            </svg>
                            {t('auth.signing_in') || 'Signing in…'}
                        </>
                    ) : (
                        <>
                            <LogIn className="h-4 w-4" />
                            {t('auth.log_in') || 'Sign in'}
                            <span className="flex h-6 w-6 items-center justify-center rounded-full bg-white/15 transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5">
                                <ArrowUpRight className="h-3.5 w-3.5" />
                            </span>
                        </>
                    )}
                </button>
            </form>

            <p className="mt-6 text-center text-sm text-[#6f6660]">
                {t('auth.no_account') || "Don't have an account?"}{' '}
                <Link
                    href={route('register')}
                    className="font-semibold text-brand-600 hover:text-brand-700"
                >
                    {t('auth.register') || 'Sign up'}
                </Link>
            </p>
        </AuthLayout>
    );
}
