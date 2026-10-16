import type { ReactNode } from 'react';
import { formatCount } from '@/lib/format';
import type { Band } from '@/types/score';

export type RankedBar = {
    key: string | number;
    label: string;
    /** Sets the bar's length only; null draws no bar. */
    value: number | null;
    /**
     * A count to read under the name ("12 facilities visited"); the number is then repeated at
     * the end of the bar for the eye. Leave it out when `detail` already prints the value.
     */
    valueText?: string;
    /** Anything else for the row, after the bar: a ScoreTag prints a score and its band. */
    detail?: ReactNode;
    /** Colours the bar by band (CONVENTION.md §6); the band is also in `detail` as text. */
    tone?: Band | null;
};

export type RankedBarsProps = {
    /** Names the list. */
    label: string;
    /** In the order to show. */
    items: RankedBar[];
    /** A reference line across every bar (the state mean), said in its label. */
    marker?: { value: number; label: string };
    /** The full length of a bar: 100 for scores; by default the longest row. */
    max?: number;
    empty: string;
};

// Strong and Acceptable share ok, as in ScoreTag: the band label tells them apart.
const TONE: Record<Band, string> = {
    strong: 'fill-ok',
    acceptable: 'fill-ok',
    review: 'fill-warn-fill',
    needs_action: 'fill-bad',
};

/**
 * Ranked horizontal bars as an ordered list (Monitor §9.2.4, Visuals §9.2.5): each row's name and
 * figure are text; the bar and the marker line are small SVGs drawn for the eye only
 * (aria-hidden). Each bar scales with its viewBox inside an HTML row, so it grows with the text at
 * 200% zoom and is never clipped. Under 640px the bar takes its own full-width line below the name. Bar lengths are layout, not figures: every number shown comes
 * from the server.
 */
export default function RankedBars({ label, items, marker, max, empty }: RankedBarsProps) {
    if (items.length === 0) {
        return <p className="px-5 py-4 text-[13px] text-ink-2">{empty}</p>;
    }

    const full = max ?? Math.max(1, marker?.value ?? 0, ...items.map((item) => item.value ?? 0));
    const length = (value: number) => Math.min(100, Math.max(0, (value / full) * 100));

    return (
        <div className="px-5 pt-2 pb-4">
            {marker !== undefined && (
                <p className="mb-2 flex items-center gap-2 text-[12px] text-ink-2">
                    <span aria-hidden="true" className="inline-block h-3.5 w-0.5 bg-warn-fill" />
                    {marker.label}
                </p>
            )}
            <ol aria-label={label} className="flex flex-col">
                {items.map((item) => (
                    <li key={item.key} className="grid grid-cols-[1fr_auto] items-center gap-x-3 border-b border-paper py-1.5 last:border-b-0 sm:grid-cols-[minmax(7rem,11rem)_1fr_auto]">
                        <span className="col-start-1 row-start-1 min-w-0 text-[13px]">
                            <span className="block font-medium break-words">{item.label}</span>
                            {item.valueText !== undefined && <span className="block text-[11.5px] text-ink-2">{item.valueText}</span>}
                        </span>
                        <svg aria-hidden="true" viewBox="0 0 100 10" preserveAspectRatio="none" className="col-span-2 row-start-2 mt-1 block h-2.5 w-full overflow-visible sm:col-span-1 sm:col-start-2 sm:row-start-1 sm:mt-0">
                            <rect x="0" y="0" width="100" height="10" rx="2" className="fill-wash" />
                            {item.value !== null && <rect x="0" y="0" width={length(item.value)} height="10" rx="2" className={item.tone === undefined || item.tone === null ? 'fill-deep' : TONE[item.tone]} />}
                            {marker !== undefined && (
                                <line x1={length(marker.value)} x2={length(marker.value)} y1="-3" y2="13" className="stroke-warn-fill" strokeWidth="2" vectorEffect="non-scaling-stroke" />
                            )}
                        </svg>
                        <span className="col-start-2 row-start-1 flex items-center gap-2 justify-self-end sm:col-start-3">
                            {item.valueText !== undefined && item.value !== null && (
                                <span aria-hidden="true" className="min-w-[2.5ch] text-right text-[13px] font-semibold tabular-nums">
                                    {formatCount(item.value)}
                                </span>
                            )}
                            {item.detail}
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
