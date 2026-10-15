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
        <div className="flex flex-wrap items-start gap-4 px-5 pt-4">
            <div className="min-w-56 flex-1">
                <Heading id={id} className={level === 2 ? 'text-[15.5px] font-semibold' : 'text-sm font-semibold'}>
                    {title}
                </Heading>
                {description !== undefined && <p className="mt-0.5 max-w-[70ch] text-[12.5px] text-ink-2">{description}</p>}
            </div>
            {actions}
        </div>
    );
}
