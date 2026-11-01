export type StateShape = '●' | '○' | '◐' | '▲' | '■' | '–';

export type StateMarkProps = {
    /** The state's shape, named by the glyph the code has always used for it. */
    mark: StateShape | string;
    className?: string;
};

/**
 * A state's shape, drawn (CLAUDE.md hard rule 11: status is never colour alone). Decorative: the
 * words beside it say the same thing. Shapes rather than font glyphs, so they sit on the text's
 * centre line at every size and render the same on every device.
 */
export default function StateMark({ mark, className = '' }: StateMarkProps) {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 10 10" className={`size-2.5 shrink-0 ${className}`}>
            {shape(mark)}
        </svg>
    );
}

function shape(mark: string) {
    switch (mark) {
        case '●':
            return <circle cx="5" cy="5" r="4" fill="currentColor" />;
        case '○':
            return <circle cx="5" cy="5" r="3.25" fill="none" stroke="currentColor" strokeWidth="1.5" />;
        case '◐':
            return (
                <>
                    <circle cx="5" cy="5" r="3.25" fill="none" stroke="currentColor" strokeWidth="1.5" />
                    <path d="M5 1.75a3.25 3.25 0 0 0 0 6.5z" fill="currentColor" />
                </>
            );
        case '▲':
            return <path d="M5 1 9.2 8.6H.8z" fill="currentColor" />;
        case '■':
            return <rect x="1.5" y="1.5" width="7" height="7" rx="0.75" fill="currentColor" />;
        default:
            return <rect x="1.5" y="4.25" width="7" height="1.5" rx="0.75" fill="currentColor" />;
    }
}
