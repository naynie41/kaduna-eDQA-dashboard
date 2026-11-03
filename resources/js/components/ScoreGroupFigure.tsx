import { lazy, Suspense } from 'react';
import DataTable from '@/components/DataTable';
import Figure from '@/components/Figure';
import ScoreTag from '@/components/ScoreTag';
import Skeleton from '@/components/Skeleton';
import { useTranslate } from '@/lib/i18n';
import { SERIES, SERIES_COLOUR } from '@/lib/series';
import type { ScoreGroupRow } from '@/types/dashboard';

// One lazy chart for every grouped-score figure, so Recharts arrives once, after the numbers
// paint (CONVENTION.md §4), and never in the first-load chunk.
const MonthGroupChart = lazy(() => import('@/components/MonthGroupChart'));

export type ScoreGroupFigureProps = {
    title: string;
    /** The caption under the title; a takeaway or what the figure shows. */
    description: string;
    /** The table's first column heading: "Month", "Quarter". */
    rowHeader: string;
    rows: ScoreGroupRow[];
};

/**
 * The three dimensions and the overall, a group of four bars per row (a month, a quarter), in a
 * Figure: a text legend in the fixed series order, the chart, and the same numbers as a table.
 * Every number is the server's; this only lays them out.
 */
export default function ScoreGroupFigure({ title, description, rowHeader, rows }: ScoreGroupFigureProps) {
    const t = useTranslate();
    const seriesName = (series: (typeof SERIES)[number]) => (series === 'overall' ? t('dashboard.visuals.overall') : t(`enums.dimension.${series}`));

    return (
        <Figure
            title={title}
            description={description}
            chart={
                <>
                    <ul aria-label={t('dashboard.visuals.legend')} className="mb-2 flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-ink-2">
                        {SERIES.map((series) => (
                            <li key={series} className="flex items-center gap-1.5">
                                <span aria-hidden="true" className="inline-block size-2.5 rounded-sm" style={{ backgroundColor: SERIES_COLOUR[series] }} />
                                {seriesName(series)}
                            </li>
                        ))}
                    </ul>
                    <Suspense fallback={<Skeleton className="h-[220px] w-full" />}>
                        <MonthGroupChart rows={rows} label={t('dashboard.visuals.chart', { title })} />
                    </Suspense>
                </>
            }
            table={
                <DataTable
                    caption={title}
                    captionHidden
                    columns={[{ key: 'row', header: rowHeader }, ...SERIES.map((series) => ({ key: series, header: seriesName(series), align: 'end' as const }))]}
                    empty={t('dashboard.visuals.no_scores')}
                >
                    {rows.map((row) => (
                        <tr key={row.key} className="border-b border-paper last:border-b-0">
                            <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                                {row.label}
                            </th>
                            {SERIES.map((series) => (
                                <td key={series} className="px-3 py-2 text-right">
                                    <ScoreTag score={row[series]} />
                                </td>
                            ))}
                        </tr>
                    ))}
                </DataTable>
            }
        />
    );
}
