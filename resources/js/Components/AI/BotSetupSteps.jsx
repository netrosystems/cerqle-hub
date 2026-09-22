import { Check } from 'lucide-react';

/**
 * Setup progress.
 *
 * A bar of numbered dots rather than cards: step titles are the only text, so a
 * long business name or a wordy status cannot break the row. Below `sm` the
 * labels would not fit at all, so it becomes one line — "Step 2 of 4" and the
 * current title — over the same bar.
 */
export default function BotSetupSteps({ steps, current, onSelect }) {
    const currentIndex = Math.max(0, steps.findIndex((step) => step.id === current));
    return (
        <div>
            <div className="flex items-baseline justify-between sm:hidden">
                <p className="text-sm font-semibold text-neutral-900 dark:text-neutral-100">{steps[currentIndex]?.title}</p>
                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                    Step {currentIndex + 1} of {steps.length}
                </p>
            </div>

            {/* One segmented bar doing both jobs on mobile: it shows progress and
                each segment is the step's own tap target. A separate bar above it
                would be the same information twice. */}
            <div className="mt-2 flex gap-1 sm:hidden">
                {steps.map((step, index) => (
                    <button
                        key={step.id}
                        type="button"
                        onClick={() => onSelect(step.id)}
                        aria-label={`Step ${index + 1}: ${step.title}`}
                        aria-current={current === step.id ? 'step' : undefined}
                        className={`h-1.5 flex-1 rounded-full transition ${
                            step.done
                                ? 'bg-brand-500'
                                : current === step.id
                                  ? 'bg-brand-300 dark:bg-brand-700'
                                  : 'bg-neutral-200 dark:bg-neutral-800'
                        }`}
                    />
                ))}
            </div>

            <ol className="hidden items-start sm:flex">
                {steps.map((step, index) => {
                    const active = current === step.id;

                    return (
                        <li key={step.id} className="flex min-w-0 flex-1 items-start">
                            <button
                                type="button"
                                onClick={() => onSelect(step.id)}
                                aria-current={active ? 'step' : undefined}
                                className="group flex min-w-0 flex-col items-center gap-1.5 px-1 focus:outline-none"
                            >
                                <span
                                    className={`flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full border text-xs font-bold transition group-focus-visible:ring-2 group-focus-visible:ring-brand-500/40 ${
                                        step.done
                                            ? 'border-brand-600 bg-brand-600 text-white'
                                            : active
                                              ? 'border-brand-500 bg-white text-brand-700 dark:bg-neutral-900 dark:text-brand-300'
                                              : 'border-neutral-300 bg-white text-neutral-400 dark:border-neutral-700 dark:bg-neutral-900'
                                    }`}
                                >
                                    {step.done ? <Check className="h-4 w-4" /> : index + 1}
                                </span>
                                <span
                                    className={`max-w-[9rem] truncate text-center text-xs ${
                                        active ? 'font-semibold text-neutral-900 dark:text-neutral-100' : 'text-neutral-500 dark:text-neutral-400'
                                    }`}
                                >
                                    {step.title}
                                </span>
                            </button>

                            {index !== steps.length - 1 && (
                                <span
                                    className={`mt-4 h-0.5 min-w-0 flex-1 rounded-full ${step.done ? 'bg-brand-500' : 'bg-neutral-200 dark:bg-neutral-800'}`}
                                    aria-hidden
                                />
                            )}
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}
