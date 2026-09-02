import { AlertCircle } from 'lucide-react';

export default function OAuthErrorAlert({ message, children = null }) {
    if (!message) return null;

    return (
        <div
            role="alert"
            aria-live="assertive"
            className="mb-4 flex items-start gap-3 rounded-xl border border-coral-200 bg-coral-50 px-4 py-3 text-coral-800 dark:border-coral-800 dark:bg-coral-950/40 dark:text-coral-300"
        >
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div className="min-w-0">
                <p className="text-sm font-medium leading-5">{message}</p>
                {children && <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs font-semibold">{children}</div>}
            </div>
        </div>
    );
}
