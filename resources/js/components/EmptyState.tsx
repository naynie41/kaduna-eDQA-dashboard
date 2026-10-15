import type { ReactNode } from 'react';

export type EmptyStateProps = {
    title: string;
    description?: string;
    action?: ReactNode;
};

/** Why there is nothing to show, in words; never a blank panel or a zero. */
export default function EmptyState({ title, description, action }: EmptyStateProps) {
    return (
        <div className="px-5 py-6 text-center text-[13.5px] text-ink-2">
            <p className="font-medium text-ink">{title}</p>
            {description !== undefined && <p className="mt-1">{description}</p>}
            {action !== undefined && <div className="mt-3">{action}</div>}
        </div>
    );
}
