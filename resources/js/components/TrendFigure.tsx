import { lazy, Suspense } from 'react';
import DataTable from '@/components/DataTable';
import Figure from '@/components/Figure';
import ScoreTag from '@/components/ScoreTag';
import Skeleton from '@/components/Skeleton';
import { formatThreshold } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Trend } from '@/types/dashboard';

// Recharts stays out of the first load (CONVENTION.md §4: charts are lazy).
const TrendChart = lazy(() => import('@/components/TrendChart'));

export type TrendFigureProps = {
    trend: Trend;
    strongAt: number | null;
};

/**
 * "Quality score by quarter": the chart in a Figure whose caption states the takeaway (from the
 * server) and whose table gives every round's overall, public and private.
 */
export default function TrendFigure({ trend, strongAt }: TrendFigureProps) {
    const t = useTranslate();
    const strongLabel = strongAt === null ? '' : t('dashboard.home.strong_line', { threshold: formatThreshold(strongAt) });
    const description = [t('dashboard.home.trend_intro'), trend.takeaway].filter((part) => part !== null && part !== '').join(' ');

    return (
        <Figure
            title={t('dashboard.home.trend_title')}
            description={description}
            chart={
                <Suspense fallback={<Skeleton className="h-[220px] w-full" />}>
                    <TrendChart points={trend.points} selected={trend.selected} strongAt={strongAt} strongLabel={strongLabel} label={t('dashboard.home.trend_chart')} />
                </Suspense>
            }
            table={
                <DataTable
                    caption={t('dashboard.home.trend_title')}
                    captionHidden
                    columns={[
                        { key: 'round', header: t('dashboard.home.round') },
                        { key: 'overall', header: t('dashboard.home.overall'), align: 'end' },
                        { key: 'public', header: t('dashboard.home.public'), align: 'end' },
                        { key: 'private', header: t('dashboard.home.private'), align: 'end' },
                    ]}
                >
                    {trend.points.map((point) => (
                        <tr key={point.roundId} className="border-b border-paper">
                            <th scope="row" className="px-3 py-2 text-left font-normal">
                                {point.label}
                                {trend.selected.includes(point.roundId) && <span className="text-[11.5px] text-ink-2"> ({t('dashboard.home.in_view')})</span>}
                            </th>
                            <td className="px-3 py-2 text-right">
                                <ScoreTag score={point.overall} />
                            </td>
                            <td className="px-3 py-2 text-right">
                                <ScoreTag score={point.public} />
                            </td>
                            <td className="px-3 py-2 text-right">
                                <ScoreTag score={point.private} />
                            </td>
                        </tr>
                    ))}
                </DataTable>
            }
        />
    );
}
