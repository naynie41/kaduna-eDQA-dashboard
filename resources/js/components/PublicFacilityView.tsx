import { Result, Section, Term } from '@/components/FacilityDetailParts';
import HistorySparkline from '@/components/HistorySparkline';
import ScoreTag from '@/components/ScoreTag';
import { useTranslate } from '@/lib/i18n';
import type { Dimension, PublicFacilityDetail } from '@/types/dashboard';

export type PublicFacilityViewProps = {
    detail: PublicFacilityDetail;
};

const DIMENSIONS: Dimension[] = ['availability', 'consistency', 'validity'];

/**
 * The public facility drawer's body (ARCHITECTURE.md §9.2.7): the score card of a closed round, its
 * item results by dimension and month, the round history, and the facility's level and ownership.
 * Nothing from SECURITY.md §3.1: the server sends only these (PublicFacilityDetail).
 */
export default function PublicFacilityView({ detail }: PublicFacilityViewProps) {
    const t = useTranslate();
    const { facility, round, scores } = detail;
    const scored = scores.overall.value !== null;

    return (
        <div className="flex flex-col gap-6">
            <Section title={round === null ? t('dashboard.drawer.scores_title_none') : t('dashboard.drawer.scores_title', { round: round.label })}>
                {!scored && (
                    <p className="mb-3 text-sm text-ink-2">
                        {round !== null ? t('dashboard.drawer.not_assessed', { round: round.label }) : t('dashboard.drawer.never_assessed')}
                    </p>
                )}
                <dl className="grid grid-cols-2 gap-2 rail:grid-cols-4">
                    {[...DIMENSIONS, 'overall' as const].map((key) => (
                        <div key={key} className="rounded-md border border-rule bg-panel px-3 py-2">
                            <dt className="text-xs text-ink-2">{key === 'overall' ? t('dashboard.drawer.overall') : t(`enums.dimension.${key}`)}</dt>
                            <dd className="mt-1">
                                <ScoreTag score={scores[key]} />
                            </dd>
                        </div>
                    ))}
                </dl>
            </Section>

            <Section title={t('dashboard.drawer.items_title')}>
                {detail.items.length === 0 ? (
                    <p className="text-sm text-ink-2">{t('dashboard.drawer.items_none')}</p>
                ) : (
                    <div className="flex flex-col gap-2">
                        {DIMENSIONS.map((dimension) => {
                            const groups = detail.items.filter((group) => group.dimension === dimension);
                            if (groups.length === 0) {
                                return null;
                            }

                            return (
                                <details key={dimension} className="rounded-md border border-rule bg-panel" open={dimension === 'availability'}>
                                    <summary className="flex min-h-11 cursor-pointer items-center gap-3 px-3 py-2 text-base font-medium">
                                        <span className="flex-1">{t(`enums.dimension.${dimension}`)}</span>
                                        <ScoreTag score={scores[dimension]} />
                                    </summary>
                                    <div className="grid gap-3 border-t border-rule px-3 py-3 rail:grid-cols-3">
                                        {groups.map((group) => (
                                            <div key={group.slot}>
                                                <h4 className="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-sm font-semibold">
                                                    {group.month}
                                                    <ScoreTag score={group.score} />
                                                </h4>
                                                <ul className="flex flex-col">
                                                    {group.items.map((item) => (
                                                        <li key={item.code} className="flex items-center justify-between gap-2 border-b border-paper py-1 text-xs last:border-b-0">
                                                            <span className="min-w-0 break-words">{item.label ?? item.code}</span>
                                                            <Result result={item.result} checks={item.checks} t={t} />
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        ))}
                                    </div>
                                </details>
                            );
                        })}
                    </div>
                )}
            </Section>

            <Section title={t('dashboard.drawer.history_title')}>
                <HistorySparkline history={detail.history} />
            </Section>

            <Section title={t('dashboard.drawer.details')}>
                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <Term label={t('dashboard.drawer.level')}>{facility.level_label}</Term>
                    <Term label={t('dashboard.drawer.owner')}>{facility.ownership_label}</Term>
                </dl>
            </Section>
        </div>
    );
}
