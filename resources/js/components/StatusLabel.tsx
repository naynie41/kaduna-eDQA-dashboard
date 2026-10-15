import type { Band } from '@/types/score';

export type StatusLabelProps = {
    band: Band | null;
    /** The band in words, as the server sent it (ScoreValue.bandLabel). */
    label: string;
};

// Band → tone (CONVENTION.md §6) and a shape, so the status survives without colour as well as
// in words. The mark is decorative: the label is what is read.
const STYLE: Record<Band, { tone: string; mark: string }> = {
    strong: { tone: 'bg-ok-bg text-ok', mark: '●' },
    acceptable: { tone: 'bg-ok-bg text-ok', mark: '◐' },
    review: { tone: 'bg-warn-bg text-warn', mark: '▲' },
    needs_action: { tone: 'bg-bad-bg text-bad', mark: '■' },
};

/** The Status column: the band's label, always as text. */
export default function StatusLabel({ band, label }: StatusLabelProps) {
    const style = band === null ? { tone: 'bg-wash text-ink-2', mark: '–' } : STYLE[band];

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11.5px] leading-normal whitespace-nowrap ${style.tone}`}>
            <span aria-hidden="true" className="text-[9px]">
                {style.mark}
            </span>
            <span>{label}</span>
        </span>
    );
}
