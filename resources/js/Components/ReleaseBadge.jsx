import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { createPortal } from 'react-dom';

export default function ReleaseBadge({ className = '' }) {
    const { app_version: appVersion, release } = usePage().props;
    const [open, setOpen] = useState(false);
    const version = release?.version || appVersion || '1.0.0';
    const changes = Array.isArray(release?.changes) ? release.changes : [];
    const deployedAt = release?.deployed_at
        ? new Date(release.deployed_at).toLocaleString()
        : null;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={`rounded-soft px-3 py-2 text-xs text-neutral-400 hover:text-neutral-200 hover:bg-white/5 dark:text-neutral-500 dark:hover:text-neutral-300 tabular-nums transition ${className}`}
                title="View deployment details"
            >
                v{version}
            </button>

            {open && createPortal(
                <div
                    className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-label={`Release v${version}`}
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) setOpen(false);
                    }}
                >
                    <div className="w-full max-w-md rounded-2xl bg-white p-5 text-left shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wider text-primary-600">Current release</p>
                                <h2 className="mt-1 text-xl font-semibold text-neutral-900 dark:text-white">Cerqle v{version}</h2>
                                {deployedAt && <p className="mt-1 text-xs text-neutral-500">Deployed {deployedAt}</p>}
                            </div>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="rounded-lg p-2 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-800 dark:hover:text-white"
                                aria-label="Close release details"
                            >
                                ×
                            </button>
                        </div>

                        {release?.commit && (
                            <p className="mt-4 text-xs text-neutral-500">
                                Commit <code className="rounded bg-neutral-100 px-1.5 py-0.5 dark:bg-neutral-800">{release.commit}</code>
                            </p>
                        )}

                        <div className="mt-4 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                            <h3 className="text-sm font-semibold text-neutral-800 dark:text-neutral-100">Changes in this deployment</h3>
                            {changes.length > 0 ? (
                                <ul className="mt-2 max-h-56 space-y-2 overflow-y-auto text-sm text-neutral-600 dark:text-neutral-300">
                                    {changes.map((change, index) => (
                                        <li key={`${change}-${index}`} className="flex gap-2">
                                            <span className="text-primary-500">•</span>
                                            <span>{change}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-2 text-sm text-neutral-500">No Git change summary was available for this build.</p>
                            )}
                        </div>
                    </div>
                </div>,
                document.body,
            )}
        </>
    );
}
