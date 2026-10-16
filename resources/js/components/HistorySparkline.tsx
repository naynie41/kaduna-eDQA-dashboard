import ScoreTag from '@/components/ScoreTag';
import VisuallyHidden from '@/components/VisuallyHidden';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { FacilityDetail } from '@/types/dashboard';

export type HistorySparklineProps = {
    history: FacilityDetail['history'];
};

const WIDTH = 220;
const HEIGHT = 48;
const PAD = 4;

/**
 * A facility's overall round over round: a small inline SVG for the eye (hidden from assistive
 * technology), a sentence saying where it started and ended, and the same figures as a table.
 * The scale is fixed at 0–100, so two facilities' lines compare; the selected round is a larger dot.
 */
export default function HistorySparkline({ history }: HistorySparklineProps) {
    const t = useTranslate();
    const first = history[0];
    const last = history[history.length - 1];

    if (first === undefined || last === undefined) {
        return <p className="text-[13px] text-ink-2">{t('dashboard.drawer.history_none')}</p>;
    }

    const step = history.length > 1 ? (WIDTH - PAD * 2) / (history.length - 1) : 0;
    const points = history.map((point, i) => ({
        x: history.length > 1 ? PAD + i * step : WIDTH / 2,
        y: point.overall.value === null ? null : PAD + (1 - point.overall.value / 100) * (HEIGHT - PAD * 2),
        selected: point.selected,
        key: point.round_id,
    }));
    const path = points
        .filter((p): p is typeof p & { y: number } => p.y !== null)
        .map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x.toFixed(1)},${p.y.toFixed(1)}`)
        .join(' ');

    const summary =
        history.length === 1
            ? t('dashboard.drawer.history_single', { score: first.overall.display, round: first.label })
            : t('dashboard.drawer.history_summary', {
                  count: formatCount(history.length),
                  first: first.overall.display,
                  first_round: first.label,
                  last: last.overall.display,
                  last_round: last.label,
              });

    return (
        <div className="flex flex-col gap-2">
            <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} width={WIDTH} height={HEIGHT} aria-hidden="true" className="max-w-full overflow-visible">
                <line x1={PAD} x2={WIDTH - PAD} y1={HEIGHT - PAD} y2={HEIGHT - PAD} className="stroke-rule" strokeWidth={1} />
                <path d={path} fill="none" className="stroke-deep" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" />
                {points.map((p) =>
                    p.y === null ? null : <circle key={p.key} cx={p.x} cy={p.y} r={p.selected ? 4 : 2.5} className={p.selected ? 'fill-deep stroke-panel' : 'fill-deep'} strokeWidth={p.selected ? 1.5 : 0} />,
                )}
            </svg>
            <p className="text-[13px]">{summary}</p>
            <table className="w-full max-w-sm text-[13px]">
                <caption className="sr-only">{t('dashboard.drawer.history_title')}</caption>
                <thead>
                    <tr className="border-b border-rule text-left text-[12px] text-ink-2">
                        <th scope="col" className="py-1 pr-3 font-medium">
                            {t('dashboard.drawer.round')}
                        </th>
                        <th scope="col" className="py-1 text-right font-medium">
                            {t('dashboard.drawer.overall')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {history.map((point) => (
                        <tr key={point.round_id} className="border-b border-paper last:border-b-0">
                            <th scope="row" className={`py-1 pr-3 text-left ${point.selected ? 'font-semibold' : 'font-normal'}`}>
                                {point.label}
                                {point.selected && <VisuallyHidden> {t('dashboard.drawer.shown')}</VisuallyHidden>}
                            </th>
                            <td className="py-1 text-right">
                                <ScoreTag score={point.overall} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
