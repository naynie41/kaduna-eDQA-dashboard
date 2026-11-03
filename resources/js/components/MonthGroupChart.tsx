import { Bar, BarChart, CartesianGrid, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import { SERIES, SERIES_COLOUR } from '@/lib/series';
import type { ScoreGroupRow } from '@/types/dashboard';

export type MonthGroupChartProps = {
    /** A group of bars per row (a quarter, a month), in time order. */
    rows: ScoreGroupRow[];
    /** Names the chart for assistive technology; the numbers are in the Figure's table. */
    label: string;
};

/**
 * A grouped-score chart (Recharts, loaded lazily; every ScoreGroupFigure shares this chunk): a group of
 * four bars per row, on a fixed 0–100 axis. No tooltip: nothing is only in a tooltip.
 * The SVG is one image to assistive technology (role="img"); the Figure's legend names the
 * series and its table holds the numbers.
 */
export default function MonthGroupChart({ rows, label }: MonthGroupChartProps) {
    const data = rows.map((row) => ({
        label: row.label,
        availability: row.availability.value,
        consistency: row.consistency.value,
        validity: row.validity.value,
        overall: row.overall.value,
    }));

    return (
        <div role="img" aria-label={label} className="h-[220px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data} margin={{ top: 8, right: 4, bottom: 4, left: 0 }} barCategoryGap="18%" barGap={1} accessibilityLayer={false}>
                    <CartesianGrid stroke="var(--color-paper)" vertical={false} />
                    <XAxis dataKey="label" interval={0} tick={{ fontSize: 11.5, fill: 'var(--color-ink-2)' }} tickLine={false} axisLine={{ stroke: 'var(--color-rule)' }} />
                    <YAxis domain={[0, 100]} ticks={[0, 25, 50, 75, 100]} tick={{ fontSize: 11.5, fill: 'var(--color-ink-2)' }} tickLine={false} axisLine={false} width={32} />
                    {SERIES.map((series) => (
                        <Bar key={series} dataKey={series} fill={SERIES_COLOUR[series]} radius={[2, 2, 0, 0]} isAnimationActive={false} />
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
