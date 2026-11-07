import type { ReactNode, Ref } from 'react';

export type TopBarProps = {
    /** The header itself, so the shell can measure it for scroll-padding-top. */
    headerRef?: Ref<HTMLElement>;
    title: string | null;
    subtitle?: string | null;
    /** Receives focus when the page changes (not when a filter does). */
    titleRef?: Ref<HTMLHeadingElement>;
    /** The filter bar and the page tabs, on dashboard pages. */
    children?: ReactNode;
    /** Beside the title: the account menu, in the admin layout only (the public site has none, D-78). */
    actions?: ReactNode;
};

/** The top bar (sticky from 760px; on a phone it would cover most of the screen): the page's h1 and subtitle, any actions beside them, then filters and tabs. */
export default function TopBar({ title, subtitle, titleRef, headerRef, children, actions }: TopBarProps) {
    return (
        <header ref={headerRef} className="z-20 border-b border-rule-strong bg-panel px-4 pt-4 rail:sticky rail:top-0 rail:px-7 rail:pt-5">
            <div className="flex flex-wrap items-start gap-x-6 gap-y-3 pb-4">
                <div className="min-w-0 flex-1 basis-80">
                    {title !== null && (
                        <h1 ref={titleRef} tabIndex={-1} className="text-lg font-semibold tracking-[-0.02em] text-ink outline-offset-4">
                            {title}
                        </h1>
                    )}
                    {subtitle !== undefined && subtitle !== null && <p className="mt-1 max-w-[72ch] text-sm text-ink-2">{subtitle}</p>}
                </div>
                {actions}
            </div>
            {children}
        </header>
    );
}
