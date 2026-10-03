import type { ReactNode } from 'react';

export type SubmitButtonProps = {
    children: ReactNode;
    processing?: boolean | undefined;
};

export default function SubmitButton({ children, processing = false }: SubmitButtonProps) {
    return (
        <button
            type="submit"
            disabled={processing}
            aria-busy={processing || undefined}
            className="inline-flex w-full items-center justify-center rounded-md bg-deep px-4 py-2.5 font-semibold text-panel hover:bg-deep-2 disabled:opacity-70"
        >
            {children}
        </button>
    );
}
