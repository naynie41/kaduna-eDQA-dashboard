/**
 * The chart series in their fixed order and palette colours (CONVENTION.md §6). Kept apart from
 * the chart components so a legend can use them without loading Recharts.
 */
export const SERIES = ['availability', 'consistency', 'validity', 'overall'] as const;

export type Series = (typeof SERIES)[number];

export const SERIES_COLOUR: Record<Series, string> = {
    availability: 'var(--color-series-availability)',
    consistency: 'var(--color-series-consistency)',
    validity: 'var(--color-series-validity)',
    overall: 'var(--color-series-overall)',
};
