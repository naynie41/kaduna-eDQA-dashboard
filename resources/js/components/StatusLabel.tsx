import StateMark from '@/components/StateMark';
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
    const style = band === null ? { tone: 'bg-paper text-ink-2', mark: '–' } : STYLE[band];

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-sm px-1.5 py-0.5 text-2xs leading-normal font-medium whitespace-nowrap ${style.tone}`}>
            <StateMark mark={style.mark} className="size-2" />
            <span>{label}</span>
        </span>
    );
}
