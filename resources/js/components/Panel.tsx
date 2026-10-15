import type { ReactNode } from 'react';

export type PanelProps = {
    children: ReactNode;
    /** The id of the heading that names the panel; makes it a landmark region. */
    labelledBy?: string;
    className?: string;
};

/** A white card on the paper background (CONVENTION.md §6). */
export default function Panel({ children, labelledBy, className = '' }: PanelProps) {
    return (
        <section aria-labelledby={labelledBy} className={`rounded-panel border border-rule bg-panel shadow-panel ${className}`}>
            {children}
        </section>
    );
}
