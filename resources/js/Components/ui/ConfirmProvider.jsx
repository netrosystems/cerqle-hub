import { createContext, useCallback, useContext, useRef, useState } from 'react';

const ConfirmContext = createContext(null);

/**
 * An in-page replacement for window.confirm().
 *
 * The native dialog cannot be relied on: once a browser has been told to stop a
 * page creating dialogs, confirm() returns false immediately and shows nothing,
 * so every guarded action fails closed and the button looks dead. That state is
 * sticky per origin and invisible to us.
 *
 * Usage mirrors the old call so the change stays mechanical:
 *
 *   const confirm = useConfirm();
 *   if (await confirm('Delete this bot?')) { … }
 */
export function useConfirm() {
    const ctx = useContext(ConfirmContext);

    // Falling back to the native dialog keeps a component usable outside the
    // provider (tests, stories) rather than throwing.
    return ctx ?? ((message) => Promise.resolve(window.confirm(message)));
}

export default function ConfirmProvider({ children }) {
    const [request, setRequest] = useState(null);
    const resolver = useRef(null);

    const confirm = useCallback((message, options = {}) => {
        return new Promise((resolve) => {
            resolver.current = resolve;
            setRequest({
                message: typeof message === 'string' ? message : (message?.message ?? ''),
                confirmLabel: options.confirmLabel ?? 'Delete',
                cancelLabel: options.cancelLabel ?? 'Cancel',
                destructive: options.destructive !== false,
            });
        });
    }, []);

    const settle = (value) => {
        setRequest(null);
        const resolve = resolver.current;
        resolver.current = null;
        resolve?.(value);
    };

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            {request && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-label={request.message}
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                    onKeyDown={(e) => e.key === 'Escape' && settle(false)}
                >
                    <div className="w-full max-w-md rounded-2xl bg-white shadow-2xl dark:bg-neutral-900">
                        <p className="px-6 pt-5 pb-4 text-sm text-neutral-800 dark:text-neutral-200">{request.message}</p>
                        <div className="flex justify-end gap-2 px-6 pb-5">
                            <button
                                type="button"
                                onClick={() => settle(false)}
                                className="rounded-lg border border-neutral-300 px-4 py-2 text-sm text-neutral-700 transition hover:bg-neutral-50 dark:border-neutral-600 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            >
                                {request.cancelLabel}
                            </button>
                            <button
                                type="button"
                                autoFocus
                                onClick={() => settle(true)}
                                className={`rounded-lg px-4 py-2 text-sm font-semibold text-white transition ${
                                    request.destructive ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700'
                                }`}
                            >
                                {request.confirmLabel}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </ConfirmContext.Provider>
    );
}
