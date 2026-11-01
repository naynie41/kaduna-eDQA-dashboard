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
            className="btn btn-primary btn-block min-h-11 text-base"
        >
            {children}
        </button>
    );
}
