import { lazy, Suspense } from 'react';
import DataTable from '@/components/DataTable';
import Figure from '@/components/Figure';
import ScoreTag from '@/components/ScoreTag';
import Skeleton from '@/components/Skeleton';
import { useTranslate } from '@/lib/i18n';
import { SERIES, SERIES_COLOUR } from '@/lib/series';
import type { MonthSlotPoint } from '@/types/dashboard';

// One lazy component for all three charts, so Recharts arrives once, after the numbers paint
// (CONVENTION.md §4), and never in the first-load chunk.
const MonthGroupChart = lazy(() => import('@/components/MonthGroupChart'));

export type MonthFigureProps = {
    slot: 1 | 2 | 3;
    /** This slot's points, a point per quarter in view, in time order. */
    points: MonthSlotPoint[];
    /** The server's one-line takeaway for this slot; null when nothing is scored. */
    takeaway: string | null;
};

/**
 * One Visuals month chart in a Figure (§9.2.5): titled with the real month when one quarter is in
 * view ("April 2026"), else "Month 1 of the quarter"; the takeaway as its caption; a text legend in
 * the fixed series order; and the same numbers as a table.
 */
export default function MonthFigure({ slot, points, takeaway }: MonthFigureProps) {
    const t = useTranslate();
    const only = points.length === 1 ? points[0] : undefined;
    const title = only !== undefined ? only.label : t('dashboard.visuals.slot_title', { slot });
    const seriesName = (series: (typeof SERIES)[number]) => (series === 'overall' ? t('dashboard.visuals.overall') : t(`enums.dimension.${series}`));

    return (
        <Figure
            title={title}
            description={takeaway ?? t('dashboard.visuals.no_scores')}
            chart={
                <>
                    <ul aria-label={t('dashboard.visuals.legend')} className="mb-2 flex flex-wrap gap-x-3.5 gap-y-1 text-[12px] text-ink-2">
                        {SERIES.map((series) => (
                            <li key={series} className="flex items-center gap-1.5">
                                <span aria-hidden="true" className="inline-block size-2.5 rounded-sm" style={{ backgroundColor: SERIES_COLOUR[series] }} />
                                {seriesName(series)}
                            </li>
                        ))}
                    </ul>
                    <Suspense fallback={<Skeleton className="h-[220px] w-full" />}>
                        <MonthGroupChart points={points} label={t('dashboard.visuals.chart', { title })} />
                    </Suspense>
                </>
            }
            table={
                <DataTable
                    caption={title}
                    captionHidden
                    columns={[
                        { key: 'quarter', header: t('dashboard.visuals.quarter') },
                        ...SERIES.map((series) => ({ key: series, header: seriesName(series), align: 'end' as const })),
                    ]}
                    empty={t('dashboard.visuals.no_scores')}
                >
                    {points.map((point) => (
                        <tr key={point.roundId} className="border-b border-paper last:border-b-0">
                            <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                                {point.chartLabel}
                            </th>
                            {SERIES.map((series) => (
                                <td key={series} className="px-3 py-2 text-right">
                                    <ScoreTag score={point[series]} />
                                </td>
                            ))}
                        </tr>
                    ))}
                </DataTable>
            }
        />
    );
}
