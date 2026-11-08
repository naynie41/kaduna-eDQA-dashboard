import type { ReactNode } from 'react';
import { useTranslate } from '@/lib/i18n';

export type PublicImpactNoteProps = {
    /** A more specific line ("Closing Q3 2026 publishes its figures."); the default says it in general. */
    children?: ReactNode;
    id?: string;
};

/**
 * One line in a dialog whose action changes what the public dashboard shows: closing or reopening
 * a round, publishing a rule version (D-76, D-77). The words carry it ("Public dashboard: …");
 * the mark beside them is decoration, hidden from assistive technology, so nothing rests on colour.
 */
export default function PublicImpactNote({ children, id }: PublicImpactNoteProps) {
    const t = useTranslate();

    return (
        <p id={id} className="notice notice-warn flex items-start gap-2 px-3 py-2 text-sm text-ink forced-colors:border-[CanvasText]">
            <svg aria-hidden="true" viewBox="0 0 10 10" className="mt-1 size-2.5 shrink-0 fill-current text-warn">
                <path d="M5 0 10 5 5 10 0 5Z" />
            </svg>
            <span>
                <strong className="font-semibold">{t('admin.public_impact.label')}</strong> {children ?? t('admin.public_impact.text')}
            </span>
        </p>
    );
}
