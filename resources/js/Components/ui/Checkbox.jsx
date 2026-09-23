/**
 * Checkbox input matching the design system tokens used in Input.jsx.
 * Props: label, error, id, name, checked, onChange, className
 */
export default function Checkbox({ label, error, id, className = '', ...props }) {
    const inputId = id || props.name;

    const errorId = error ? `${inputId}-error` : undefined;

    // The error sits under the row, not inside it. As a third flex child it was
    // squeezed into a narrow column beside the label, which on the Register
    // page put "accept the Terms" far from the Google button that triggered it
    // and easy to miss.
    return (
        <div>
            <div className="flex items-start gap-2">
                <input
                    id={inputId}
                    type="checkbox"
                    aria-invalid={error ? true : undefined}
                    aria-describedby={errorId}
                    className={[
                        'mt-0.5 h-4 w-4 rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-brand-500 shadow-inner transition duration-150 focus:ring-2 focus:ring-brand-500/20 focus:ring-offset-0 dark:checked:bg-brand-500 checked:border-brand-500',
                        className,
                    ].filter(Boolean).join(' ')}
                    {...props}
                />
                {label && (
                    <label
                        htmlFor={inputId}
                        className="select-none text-sm text-neutral-700 dark:text-neutral-300"
                    >
                        {label}
                    </label>
                )}
            </div>
            {error && (
                <p id={errorId} className="mt-1 pl-6 text-sm text-red-500 dark:text-red-400">{error}</p>
            )}
        </div>
    );
}
