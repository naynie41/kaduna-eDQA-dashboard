import type { Band, ScoreValue } from '@/types/score';

export type DimensionBarProps = {
    label: string;
    /** One line under the name: what the dimension asks. */
    help: string;
    score: ScoreValue;
    /** The Strong threshold, where the mark sits; null hides it. */
    strongAt: number | null;
};

// Strong and Acceptable deep teal, Review warn-fill, Needs action bad (CONVENTION.md §6). The
// colour only repeats the band, which is also written beside the value.
const FILL: Record<Band, string> = {
    strong: 'bg-deep',
    acceptable: 'bg-deep',
    review: 'bg-warn-fill',
    needs_action: 'bg-bad',
};

/**
 * One dimension in the hero: name, help, a bar to its value with the Strong mark, and the value
 * with its band in words. The bar and mark are decorative (aria-hidden): the list item's text
 * carries everything. Width is geometry from ScoreValue.value; nothing is rounded or banded here.
 */
export default function DimensionBar({ label, help, score, strongAt }: DimensionBarProps) {
    const width = score.value === null ? 0 : Math.min(100, Math.max(0, score.value));

    return (
        <li className="grid grid-cols-[minmax(0,96px)_1fr_auto] items-center gap-2.5 rail:grid-cols-[118px_1fr_auto] rail:gap-3.5">
            <span className="text-[13px] leading-tight">
                {label}
                <span className="block text-[11px] text-ink-2">{help}</span>
            </span>
            <span aria-hidden="true" className="relative h-5 overflow-hidden rounded bg-paper">
                {score.band !== null && <span className={`block h-full rounded motion-safe:transition-[width] ${FILL[score.band]}`} style={{ width: `${width}%` }} />}
                {strongAt !== null && <span className="absolute inset-y-0 w-[1.5px] bg-ink/40" style={{ left: `${strongAt}%` }} />}
            </span>
            <span className="min-w-[56px] text-right leading-tight">
                <span className="block text-sm font-semibold tabular-nums">{score.display}</span>
                <span className="block text-[11px] text-ink-2">{score.bandLabel}</span>
            </span>
        </li>
    );
}
