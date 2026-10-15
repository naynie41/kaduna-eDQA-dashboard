import type { ReactNode } from 'react';

export type VisuallyHiddenProps = {
    children: ReactNode;
    as?: 'span' | 'div';
};

/** Text for assistive technology only: off screen, never display:none. */
export default function VisuallyHidden({ children, as: Tag = 'span' }: VisuallyHiddenProps) {
    return <Tag className="sr-only">{children}</Tag>;
}
