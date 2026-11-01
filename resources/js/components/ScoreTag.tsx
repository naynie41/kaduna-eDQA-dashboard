import type { Band, ScoreValue } from '@/types/score';

export type ScoreTagProps = {
    score: ScoreValue;
};

// CONVENTION.md §6 band colours: Strong and Acceptable → ok, Review → warn, Needs action → bad.
// Strong and Acceptable share a colour, so the label is what tells them apart: colour never
// carries the meaning alone (CLAUDE.md hard rule 11).
const TONE: Record<Band, string> = {
    strong: 'bg-ok-bg text-ok',
    acceptable: 'bg-ok-bg text-ok',
    review: 'bg-warn-bg text-warn',
    needs_action: 'bg-bad-bg text-bad',
};

const NO_DATA_TONE = 'bg-wash text-ink-2';

/** A score with its band, both as text. Renders the server's values as they are. */
export default function ScoreTag({ score }: ScoreTagProps) {
    const tone = score.band === null ? NO_DATA_TONE : TONE[score.band];

    return (
        <span className={`inline-flex items-baseline gap-1.5 rounded-sm px-1.5 py-0.5 text-sm font-semibold tabular-nums ${tone}`}>
            <span>{score.display}</span>
            <span className="text-2xs font-medium">{score.bandLabel}</span>
        </span>
    );
}
