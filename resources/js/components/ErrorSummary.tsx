import { useEffect, useRef } from 'react';
import { useTranslate } from '@/lib/i18n';

export type ErrorSummaryProps = {
    /** Field name → message, as Inertia returns them. Each links to the input with that id. */
    errors: Partial<Record<string, string>>;
};

/**
 * The form's errors at the top, announced and focused when they appear, each linking to its
 * field (WCAG 3.3.1, 3.3.3). Fields repeat their own message next to the input.
 */
export default function ErrorSummary({ errors }: ErrorSummaryProps) {
    const t = useTranslate();
    const ref = useRef<HTMLDivElement>(null);
    const entries = Object.entries(errors).filter((entry): entry is [string, string] => Boolean(entry[1]));
    const signature = entries.map(([field, message]) => `${field}:${message}`).join('|');

    useEffect(() => {
        if (signature !== '') {
            ref.current?.focus();
        }
    }, [signature]);

    if (entries.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            aria-labelledby="error-summary-title"
            className="mb-6 rounded-md border-2 border-bad bg-bad-bg p-4"
        >
            <h2 id="error-summary-title" className="text-base font-semibold text-bad">
                {t('common.error_summary_title')}
            </h2>
            <ul className="mt-2 list-disc space-y-1 pl-5">
                {entries.map(([field, message]) => (
                    <li key={field}>
                        <a href={`#${field}`} className="text-bad underline underline-offset-2">
                            {message}
                        </a>
                    </li>
                ))}
            </ul>
        </div>
    );
}
