import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useTranslate } from '@/lib/i18n';

export const REASON_MIN_LENGTH = 10;

export type ConfirmOutcome = { ok: true } | { ok: false; message: string; fieldError?: string | null };

export type ConfirmWithReasonDialogProps = {
    open: boolean;
    title: string;
    /** What will happen, in plain words: shown under the title and read with it. */
    consequence: ReactNode;
    confirmLabel: string;
    /** Sends the action with the reason (trimmed). Resolves ok, or with the message to show. */
    onConfirm: (reason: string) => Promise<ConfirmOutcome>;
    /** Esc, Cancel, or a successful confirm. Focus then returns to what opened the dialog. */
    onClose: () => void;
    /** The confirm button in the danger style (reject, deactivate). */
    danger?: boolean;
    minLength?: number;
};

const FOCUSABLE = 'button:not([disabled]), textarea:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

/**
 * Every reasoned action (CONVENTION.md §6: reject quarantine, override flag, correct assessment,
 * reopen round, publish rule version, deactivate facility). A native modal <dialog>, named by
 * its title and described by the consequence text; a labelled textarea whose hint ("at least 10
 * characters") is linked with aria-describedby, as is a server error for the reason; the confirm
 * button stays disabled until the reason is long enough (the server checks it again, with the
 * same rule: trimmed, at least 10 characters). A failure is announced (role=alert) and the dialog
 * stays open with the reason kept. Tab stays inside; Esc and Cancel close; focus returns to the
 * trigger.
 */
export default function ConfirmWithReasonDialog({
    open,
    title,
    consequence,
    confirmLabel,
    onConfirm,
    onClose,
    danger = false,
    minLength = REASON_MIN_LENGTH,
}: ConfirmWithReasonDialogProps) {
    const t = useTranslate();
    const ids = { title: useId(), consequence: useId(), reason: useId(), hint: useId(), error: useId() };
    const dialogRef = useRef<HTMLDialogElement>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const openerRef = useRef<HTMLElement | null>(null);
    const onCloseRef = useRef(onClose);
    useEffect(() => {
        onCloseRef.current = onClose;
    });

    const [reason, setReason] = useState('');
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<{ message: string; field: string | null } | null>(null);
    const length = [...reason.trim()].length;
    const remaining = Math.max(0, minLength - length);

    // Open and close the native dialog with the prop; remember and restore the trigger's focus.
    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) {
            return;
        }
        if (open && !dialog.open) {
            openerRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            setReason('');
            setError(null);
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
            textareaRef.current?.focus();
        } else if (!open && dialog.open) {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
            openerRef.current?.focus();
            openerRef.current = null;
        }
    }, [open]);

    // Esc (the browser's cancel event, and the key itself where a browser has no modal dialog)
    // and Tab wrapping inside.
    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null || !open) {
            return;
        }
        const onCancel = (event: Event) => {
            event.preventDefault(); // close through the prop, so React state and focus stay right
            onCloseRef.current();
        };
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onCloseRef.current();

                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const focusable = [...dialog.querySelectorAll<HTMLElement>(FOCUSABLE)];
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (first === undefined || last === undefined) {
                return;
            }
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        dialog.addEventListener('cancel', onCancel);
        dialog.addEventListener('keydown', onKeyDown);

        return () => {
            dialog.removeEventListener('cancel', onCancel);
            dialog.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        if (pending || remaining > 0) {
            return;
        }
        setPending(true);
        setError(null);
        const outcome = await onConfirm(reason.trim());
        setPending(false);
        if (outcome.ok) {
            onCloseRef.current();

            return;
        }
        setError({ message: outcome.message, field: outcome.fieldError ?? null });
        textareaRef.current?.focus();
    };

    const describedBy = [ids.hint, error?.field !== null && error !== null ? ids.error : null].filter((id) => id !== null).join(' ');

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby={ids.title}
            aria-describedby={ids.consequence}
            className="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-panel border border-rule bg-panel p-0 text-ink shadow-panel backdrop:bg-ink/40"
        >
            {open && (
                <form onSubmit={submit} className="flex flex-col gap-3 p-5" noValidate>
                    <h2 id={ids.title} className="text-[17px]">
                        {title}
                    </h2>
                    <div id={ids.consequence} className="text-[13.5px]">
                        {consequence}
                    </div>

                    <div className="flex flex-col gap-1">
                        <label htmlFor={ids.reason} className="text-[13px] font-medium">
                            {t('common.reason.label')}
                        </label>
                        <textarea
                            ref={textareaRef}
                            id={ids.reason}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={4}
                            required
                            aria-describedby={describedBy}
                            aria-invalid={error?.field !== null && error !== null ? true : undefined}
                            className="min-h-24 rounded-md border border-rule bg-panel px-3 py-2 text-[14px] aria-invalid:border-bad"
                        />
                        <p id={ids.hint} className="text-[12.5px] text-ink-2">
                            {t('common.reason.hint', { min: minLength })}
                            {remaining > 0 && reason !== '' && <span> {t('common.reason.remaining', { count: remaining })}</span>}
                        </p>
                        {error !== null && error.field !== null && (
                            <p id={ids.error} className="text-[12.5px] text-bad">
                                {error.field}
                            </p>
                        )}
                    </div>

                    {error !== null && (
                        <p role="alert" className="rounded-md border border-bad bg-bad-bg px-3 py-2 text-[13px] text-bad">
                            {error.message}
                        </p>
                    )}

                    <div className="flex flex-wrap justify-end gap-2 pt-1">
                        <button type="button" onClick={() => onCloseRef.current()} className="min-h-11 rounded-md border border-rule px-4 text-[13.5px] hover:bg-paper">
                            {t('common.ui.cancel')}
                        </button>
                        <button
                            type="submit"
                            disabled={remaining > 0 || pending}
                            aria-describedby={remaining > 0 ? ids.hint : undefined}
                            className={`min-h-11 rounded-md px-4 text-[13.5px] font-medium text-panel disabled:cursor-not-allowed disabled:opacity-50 ${danger ? 'bg-bad' : 'bg-deep'}`}
                        >
                            {pending ? t('common.ui.working') : confirmLabel}
                        </button>
                    </div>
                </form>
            )}
        </dialog>
    );
}
