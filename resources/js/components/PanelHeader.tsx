import type { ReactNode } from 'react';

export type PanelHeaderProps = {
    title: string;
    /** Give the panel's labelledBy the same id. */
    id?: string;
    description?: string;
    actions?: ReactNode;
    level?: 2 | 3;
};

/** A panel's heading, its one-line explanation, and any controls on the right. */
export default function PanelHeader({ title, id, description, actions, level = 2 }: PanelHeaderProps) {
    const Heading = level === 2 ? 'h2' : 'h3';

    return (
        <div className="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 pt-4.5">
            <div className="min-w-56 flex-1">
                <Heading id={id} className={level === 2 ? 'text-md font-semibold tracking-[-0.015em]' : 'text-base font-semibold'}>
                    {title}
                </Heading>
                {description !== undefined && <p className="mt-1 max-w-[72ch] text-sm text-ink-2">{description}</p>}
            </div>
            {actions}
        </div>
    );
}
