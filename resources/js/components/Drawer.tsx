import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export type DrawerProps = {
    /** The heading, which names the dialog and takes focus when it opens. */
    title: ReactNode;
    /** A line under the heading. */
    subtitle?: ReactNode;
    closeLabel: string;
    onClose: () => void;
    children: ReactNode;
};

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';

/** Tab can land on it: not hidden, and not inside a closed <details> (its own summary is). */
function reachable(el: HTMLElement): boolean {
    if (el.closest('[hidden], [inert]') !== null) {
        return false;
    }
    const closed = el.closest('details:not([open])');

    return closed === null || (el.tagName === 'SUMMARY' && el.parentElement === closed);
}

/**
 * A modal sheet (ACCESSIBILITY.md §3, §6): role=dialog, aria-modal, named by its heading. On open,
 * focus moves to the heading; Tab and Shift+Tab stay inside; Esc, the close button or a click on
 * the backdrop close it, and focus returns to what opened it. From 760px it slides in from the
 * right; under 760px it is a full-screen sheet.
 */
export default function Drawer({ title, subtitle, closeLabel, onClose, children }: DrawerProps) {
    const titleId = useId();
    const panelRef = useRef<HTMLDivElement>(null);
    const headingRef = useRef<HTMLHeadingElement>(null);
    const onCloseRef = useRef(onClose);
    useEffect(() => {
        onCloseRef.current = onClose;
    });

    useEffect(() => {
        const opener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const root = document.documentElement;
        const overflow = root.style.overflow;
        root.style.overflow = 'hidden'; // the page behind does not scroll
        headingRef.current?.focus();

        // Focus that leaves the dialog some other way (a click behind it) is brought back.
        const keepInside = (event: FocusEvent) => {
            const panel = panelRef.current;
            if (panel !== null && event.target instanceof Node && !panel.contains(event.target)) {
                headingRef.current?.focus();
            }
        };
        // Esc closes; Tab and Shift+Tab wrap around inside the dialog.
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onCloseRef.current();

                return;
            }
            const panel = panelRef.current;
            if (event.key !== 'Tab' || panel === null) {
                return;
            }
            const focusable = [...panel.querySelectorAll<HTMLElement>(FOCUSABLE)].filter(reachable);
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (first === undefined || last === undefined) {
                event.preventDefault();

                return;
            }
            const active = document.activeElement;
            if (event.shiftKey && (active === first || active === headingRef.current)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && active === last) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('focusin', keepInside);
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('focusin', keepInside);
            document.removeEventListener('keydown', onKeyDown);
            root.style.overflow = overflow;
            // Back to the trigger; opened from a link there is none, so to the page's heading.
            const target = opener !== null && opener.isConnected && opener !== document.body ? opener : document.querySelector<HTMLElement>('h1[tabindex]');
            target?.focus();
        };
    }, []);

    return createPortal(
        <>
            <div className="fixed inset-0 z-40 hidden bg-ink/40 rail:block" aria-hidden="true" onClick={onClose} />
            <div
                ref={panelRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className="fixed inset-0 z-50 flex flex-col bg-paper rail:left-auto rail:w-[min(640px,92vw)] rail:border-l rail:border-rule rail:shadow-panel"
            >
                <div className="flex items-start gap-3 border-b border-rule bg-panel px-5 py-4">
                    <div className="min-w-0 flex-1">
                        <h2 id={titleId} ref={headingRef} tabIndex={-1} className="text-[17px] leading-snug outline-offset-4">
                            {title}
                        </h2>
                        {subtitle !== undefined && <p className="mt-0.5 text-[12.5px] text-ink-2">{subtitle}</p>}
                    </div>
                    <button type="button" onClick={onClose} className="min-h-9 shrink-0 rounded-md border border-rule bg-panel px-3 text-[13px] hover:bg-paper">
                        {closeLabel}
                    </button>
                </div>
                <div className="flex-1 overflow-y-auto overscroll-contain px-5 py-4">{children}</div>
            </div>
        </>,
        document.body,
    );
}
