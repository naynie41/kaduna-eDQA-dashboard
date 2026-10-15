import { CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, XAxis, YAxis } from 'recharts';
import type { TrendPoint } from '@/types/dashboard';

export type TrendChartProps = {
    points: TrendPoint[];
    selected: number[];
    strongAt: number | null;
    strongLabel: string;
    /** Names the chart for assistive technology; the numbers are in the Figure's table. */
    label: string;
};

type Datum = { label: string; value: number | null; display: string; selected: boolean };

/**
 * The quarter trend (Recharts, loaded lazily): the overall per round, the rounds in view drawn
 * solid and labelled, the Strong threshold as a dashed warn-fill line with its label. The SVG is
 * one image to assistive technology (role="img"); the Figure's table holds the numbers.
 */
export default function TrendChart({ points, selected, strongAt, strongLabel, label }: TrendChartProps) {
    const data: Datum[] = points.map((point) => ({
        label: point.label,
        value: point.overall.value,
        display: point.overall.display,
        selected: selected.includes(point.roundId),
    }));

    return (
        <div role="img" aria-label={label} className="h-[220px] w-full">
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={data} margin={{ top: 22, right: 64, bottom: 4, left: -8 }} accessibilityLayer={false}>
                    <CartesianGrid stroke="var(--color-paper)" vertical={false} />
                    <XAxis dataKey="label" padding={{ left: 22, right: 22 }} tick={{ fontSize: 11, fill: 'var(--color-ink-2)' }} tickLine={false} axisLine={{ stroke: 'var(--color-rule)' }} />
                    <YAxis domain={[50, 100]} allowDataOverflow ticks={[50, 60, 70, 80, 90, 100]} tick={{ fontSize: 11, fill: 'var(--color-ink-2)' }} tickLine={false} axisLine={false} width={40} />
                    {strongAt !== null && (
                        <ReferenceLine
                            y={strongAt}
                            stroke="var(--color-warn-fill)"
                            strokeWidth={1.5}
                            strokeDasharray="5 4"
                            label={{ value: strongLabel, position: 'right', fill: 'var(--color-warn)', fontSize: 11 }}
                        />
                    )}
                    <Line
                        type="linear"
                        dataKey="value"
                        stroke="var(--color-deep)"
                        strokeWidth={2.5}
                        connectNulls={false}
                        isAnimationActive={false}
                        dot={(props: { cx?: number; cy?: number; index?: number }) => {
                            const datum = data[props.index ?? -1];
                            if (props.cx === undefined || props.cy === undefined || datum === undefined || datum.value === null) {
                                return <g key={props.index} />;
                            }

                            return (
                                <circle
                                    key={props.index}
                                    cx={props.cx}
                                    cy={props.cy}
                                    r={datum.selected ? 5.5 : 4}
                                    fill={datum.selected ? 'var(--color-deep)' : 'var(--color-panel)'}
                                    stroke="var(--color-deep)"
                                    strokeWidth={2.5}
                                />
                            );
                        }}
                        label={(props: { x?: number | string | undefined; y?: number | string | undefined; index?: number | undefined }) => {
                            // Each value on the side of its point away from the Strong line, so the
                            // dashed line never runs through a number.
                            const datum = data[props.index ?? -1];
                            if (datum === undefined || datum.value === null || props.x === undefined || props.y === undefined) {
                                return <g key={props.index} />;
                            }
                            const above = strongAt === null || datum.value >= strongAt;

                            return (
                                <text key={props.index} x={Number(props.x)} y={Number(props.y) + (above ? -12 : 20)} textAnchor="middle" fontSize={11} fontWeight={600} fill="var(--color-ink-2)">
                                    {datum.display}
                                </text>
                            );
                        }}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
