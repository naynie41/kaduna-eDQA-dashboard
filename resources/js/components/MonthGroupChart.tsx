import { Bar, BarChart, CartesianGrid, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import { SERIES, SERIES_COLOUR } from '@/lib/series';
import type { MonthSlotPoint } from '@/types/dashboard';

export type MonthGroupChartProps = {
    /** One month slot, a point per quarter in view, in time order. */
    points: MonthSlotPoint[];
    /** Names the chart for assistive technology; the numbers are in the Figure's table. */
    label: string;
};

/**
 * One Visuals month chart (Recharts, loaded lazily; all three charts share this chunk): a group of
 * four bars per quarter in view, on a fixed 0–100 axis. No tooltip: nothing is only in a tooltip.
 * The SVG is one image to assistive technology (role="img"); the Figure's legend names the
 * series and its table holds the numbers.
 */
export default function MonthGroupChart({ points, label }: MonthGroupChartProps) {
    const data = points.map((point) => ({
        label: point.chartLabel,
        availability: point.availability.value,
        consistency: point.consistency.value,
        validity: point.validity.value,
        overall: point.overall.value,
    }));

    return (
        <div role="img" aria-label={label} className="h-[220px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <BarChart data={data} margin={{ top: 8, right: 4, bottom: 4, left: 0 }} barCategoryGap="18%" barGap={1} accessibilityLayer={false}>
                    <CartesianGrid stroke="var(--color-paper)" vertical={false} />
                    <XAxis dataKey="label" interval={0} tick={{ fontSize: 10.5, fill: 'var(--color-ink-2)' }} tickLine={false} axisLine={{ stroke: 'var(--color-rule)' }} />
                    <YAxis domain={[0, 100]} ticks={[0, 25, 50, 75, 100]} tick={{ fontSize: 11, fill: 'var(--color-ink-2)' }} tickLine={false} axisLine={false} width={32} />
                    {SERIES.map((series) => (
                        <Bar key={series} dataKey={series} fill={SERIES_COLOUR[series]} radius={[2, 2, 0, 0]} isAnimationActive={false} />
                    ))}
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
}
