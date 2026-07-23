import { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';

const STORAGE_KEY = 'cookie_consent';

export default function CookieConsent() {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);
    const [showManage, setShowManage] = useState(false);

    useEffect(() => {
        if (!localStorage.getItem(STORAGE_KEY)) {
            setVisible(true);
        }
    }, []);

    const accept = () => {
        localStorage.setItem(STORAGE_KEY, 'accepted');
        setVisible(false);
    };

    const decline = () => {
        localStorage.setItem(STORAGE_KEY, 'declined');
        setVisible(false);
    };

    if (!visible) return null;

    return (
        <div className="fixed inset-x-4 bottom-4 z-50 sm:inset-x-auto sm:left-4 sm:max-w-[430px]">
            <div className="overflow-hidden rounded-xl border border-slate-200/80 bg-white/95 p-4 text-slate-700 shadow-[0_18px_45px_rgba(15,23,42,0.18)] backdrop-blur sm:p-5">
                <div className="flex gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-50 text-xl shadow-sm">
                        🍪
                    </div>
                    <div className="min-w-0">
                        <h2 className="text-base font-bold text-slate-950">
                            {t('ui.cookie_title', { defaultValue: 'We use cookies' })}
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            {t('ui.cookie_consent_message', {
                                defaultValue: 'We use cookies to enhance your experience, analyze traffic, and personalize content.',
                            })}{' '}
                            <a href="/p/cookies" className="font-semibold text-brand-600 underline-offset-2 hover:underline">
                                {t('ui.cookie_policy', { defaultValue: 'Cookie Policy' })}
                            </a>
                        </p>
                    </div>
                </div>

                {showManage && (
                    <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-600">
                        {t('ui.cookie_manage_note', {
                            defaultValue: 'Essential cookies keep the site working. Analytics and personalization cookies help us improve Cerqle after you accept.',
                        })}
                    </div>
                )}

                <div className="mt-5 flex flex-wrap items-center gap-3">
                    <button
                        onClick={accept}
                        className="rounded-[4px] bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700"
                    >
                        {t('ui.cookie_accept_all', { defaultValue: 'Accept all' })}
                    </button>
                    <button
                        onClick={decline}
                        className="rounded-[4px] border border-brand-600 bg-white px-5 py-3 text-sm font-semibold text-brand-600 transition hover:bg-brand-50"
                    >
                        {t('ui.cookie_reject_all', { defaultValue: 'Reject all' })}
                    </button>
                    <button
                        type="button"
                        onClick={() => setShowManage((current) => !current)}
                        className="inline-flex items-center gap-1.5 px-2 py-3 text-sm font-semibold text-brand-600 transition hover:text-brand-700"
                        aria-expanded={showManage}
                    >
                        {t('ui.cookie_manage', { defaultValue: 'Manage Cookies' })}
                        <svg
                            className={`h-4 w-4 transition-transform ${showManage ? 'rotate-180' : ''}`}
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={2}
                            viewBox="0 0 24 24"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 9l6 6 6-6" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    );
}
