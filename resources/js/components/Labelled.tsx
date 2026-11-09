import type { ReactNode } from 'react';

export type LabelledProps = {
    /** The control's id: the label points at it. */
    id: string;
    label: string;
    children: ReactNode;
};

/** A small label above one form control (the admin filter and bulk bars). */
export default function Labelled({ id, label, children }: LabelledProps) {
    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-xs text-ink-2">
                {label}
            </label>
            {children}
        </div>
    );
}
