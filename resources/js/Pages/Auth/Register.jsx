import AuthLayout from '@/Layouts/AuthLayout';
import OAuthErrorAlert from '@/Components/Auth/OAuthErrorAlert';
import { Button, Input, Checkbox } from '@/Components/ui';
import { Head, Link, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { UserPlus } from 'lucide-react';
import { browserTz } from '@/Utils/datetime';

export default function Register({ plan_id = null, cycle = 'month', googleSignupEnabled = false }) {
    const { t } = useTranslation();
    const { data, setData, post, processing, errors, reset, setError, clearErrors } = useForm({
        name:                  '',
        email:                 '',
        password:              '',
        password_confirmation: '',
        agree_terms:           false,
        plan_id:               plan_id ?? '',
        cycle:                 cycle,
        timezone:              browserTz() || 'Asia/Dhaka',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    const googleSignup = () => {
        if (!data.agree_terms) {
            setError(
                'agree_terms',
                t('auth.google_terms_required', {
                    defaultValue: 'Accept the Terms & Conditions and Privacy Policy to continue with Google.',
                }),
            );
            document.getElementById('agree_terms')?.focus();
            return;
        }

        // Use the same Inertia form instance as the email registration flow so
        // server-side validation errors remain attached to the visible form.
        post(route('auth.google.signup'), { preserveScroll: true });
    };

    return (
        <AuthLayout
            title={t('auth.register') || 'Create an account'}
            subtitle={t('auth.register_subtitle') || 'Get started for free today'}
        >
            <Head title={t('auth.register') || 'Register'} />

            <OAuthErrorAlert message={errors.oauth}>
                <Link
                    href={route('login')}
                    className="underline decoration-coral-300 underline-offset-2 hover:text-coral-950 dark:hover:text-coral-100"
                >
                    {t('auth.sign_in_instead', { defaultValue: 'Sign in instead' })}
                </Link>
            </OAuthErrorAlert>

            {googleSignupEnabled && (
                <>
                    <Button type="button" variant="outline" className="w-full" disabled={processing} onClick={googleSignup}>
                        <svg className="mr-2 h-4 w-4" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#4285F4" d="M21.6 12.23c0-.71-.06-1.4-.18-2.07H12v3.91h5.38a4.6 4.6 0 0 1-2 3.02v2.54h3.24c1.9-1.75 2.98-4.33 2.98-7.4Z" />
                            <path fill="#34A853" d="M12 22c2.7 0 4.97-.9 6.62-2.43l-3.24-2.54c-.9.6-2.05.96-3.38.96-2.6 0-4.8-1.76-5.6-4.12H3.05v2.62A10 10 0 0 0 12 22Z" />
                            <path fill="#FBBC05" d="M6.4 13.87A6.02 6.02 0 0 1 6.08 12c0-.65.11-1.29.32-1.87V7.51H3.05A10 10 0 0 0 2 12c0 1.61.39 3.14 1.05 4.49l3.35-2.62Z" />
                            <path fill="#EA4335" d="M12 6.01c1.47 0 2.79.5 3.83 1.5l2.87-2.87A9.63 9.63 0 0 0 12 2a10 10 0 0 0-8.95 5.51l3.35 2.62C7.2 7.77 9.4 6.01 12 6.01Z" />
                        </svg>
                        Continue with Google
                    </Button>
                    <div className="flex items-center gap-3 text-xs text-neutral-400"><span className="h-px flex-1 bg-neutral-200 dark:bg-neutral-700" /><span>or use email</span><span className="h-px flex-1 bg-neutral-200 dark:bg-neutral-700" /></div>
                </>
            )}

            <form onSubmit={submit} className="space-y-4">
                <Input
                    id="name"
                    name="name"
                    label={t('auth.name') || 'Full name'}
                    value={data.name}
                    autoComplete="name"
                    autoFocus
                    onChange={(e) => setData('name', e.target.value)}
                    error={errors.name}
                    required
                />

                <Input
                    id="email"
                    type="email"
                    name="email"
                    label={t('auth.email') || 'Email address'}
                    value={data.email}
                    autoComplete="username"
                    onChange={(e) => setData('email', e.target.value)}
                    error={errors.email}
                    required
                />

                <Input
                    id="password"
                    type="password"
                    name="password"
                    label={t('auth.password') || 'Password'}
                    value={data.password}
                    autoComplete="new-password"
                    onChange={(e) => setData('password', e.target.value)}
                    error={errors.password}
                    required
                />

                <Input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    label={t('auth.confirm_password') || 'Confirm password'}
                    value={data.password_confirmation}
                    autoComplete="new-password"
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    error={errors.password_confirmation}
                    required
                />

                <Checkbox
                    id="agree_terms"
                    name="agree_terms"
                    checked={data.agree_terms}
                    onChange={(e) => {
                        setData('agree_terms', e.target.checked);
                        if (e.target.checked) clearErrors('agree_terms');
                    }}
                    error={errors.agree_terms}
                    label={
                        <span>
                            {t('auth.agree_prefix') || 'I agree to the'}{' '}
                            <a
                                href="/p/terms"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-brand-600 dark:text-brand-400 hover:underline"
                            >
                                {t('auth.terms_of_service') || 'Terms & Conditions'}
                            </a>{' '}
                            {t('auth.and') || 'and'}{' '}
                            <a
                                href="/p/privacy"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="font-medium text-brand-600 dark:text-brand-400 hover:underline"
                            >
                                {t('auth.privacy_policy') || 'Privacy Policy'}
                            </a>
                        </span>
                    }
                />

                <Button type="submit" variant="primary" className="w-full" disabled={processing}>
                    <UserPlus className="mr-2 h-4 w-4" />
                    {processing ? (t('auth.creating_account') || 'Creating account…') : (t('auth.register') || 'Create account')}
                </Button>
                {/* Hidden fields for plan-aware registration */}
                {data.plan_id && <input type="hidden" name="plan_id" value={data.plan_id} />}
                <input type="hidden" name="cycle" value={data.cycle} />
                <input type="hidden" name="timezone" value={data.timezone} />
            </form>

            <p className="mt-5 text-center text-sm text-neutral-500 dark:text-neutral-400">
                {t('auth.already_registered') || 'Already have an account?'}{' '}
                <Link href={route('login')} className="font-medium text-brand-600 dark:text-brand-400 hover:underline">
                    {t('auth.log_in') || 'Sign in'}
                </Link>
            </p>
        </AuthLayout>
    );
}
