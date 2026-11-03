import ScoreGroupFigure from '@/components/ScoreGroupFigure';
import { useTranslate } from '@/lib/i18n';
import type { MonthSlotPoint } from '@/types/dashboard';

export type MonthFigureProps = {
    slot: 1 | 2 | 3;
    /** This slot's points, a point per quarter in view, in time order. */
    points: MonthSlotPoint[];
    /** The server's one-line takeaway for this slot; null when nothing is scored. */
    takeaway: string | null;
};

/**
 * One Visuals month chart (§9.2.5): titled with the real month when one quarter is in view
 * ("April 2026"), else "Month 1 of the quarter"; the takeaway as its caption; a group of bars per
 * quarter in view, and the same numbers as a table.
 */
export default function MonthFigure({ slot, points, takeaway }: MonthFigureProps) {
    const t = useTranslate();
    const only = points.length === 1 ? points[0] : undefined;
    const title = only !== undefined ? only.label : t('dashboard.visuals.slot_title', { slot });

    return (
        <ScoreGroupFigure
            title={title}
            description={takeaway ?? t('dashboard.visuals.no_scores')}
            rowHeader={t('dashboard.visuals.quarter')}
            rows={points.map((point) => ({ ...point, key: point.roundId, label: point.chartLabel }))}
        />
    );
}
