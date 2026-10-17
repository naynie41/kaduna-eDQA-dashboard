import { Deferred } from '@inertiajs/react';
import DataTable, { type DataTableColumn } from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import HeatCell from '@/components/HeatCell';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import ScoreTag from '@/components/ScoreTag';
import Skeleton from '@/components/Skeleton';
import { formatCount, formatDate } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { Plan, PlanProps } from '@/types/dashboard';

/**
 * Implementation plan (ARCHITECTURE.md §9.2.6), read-only: an action for every local government
 * below the threshold, those needing none, the actions by type and what each means. Every figure,
 * count and text comes from ImplementationPlanAggregator. Editing arrives in Phase 6.
 */
export default function PlanPage({ plan }: PlanProps) {
    return (
        <Deferred data="plan" fallback={<Skeleton className="h-[640px] w-full" />}>
            {plan !== undefined ? <PlanView plan={plan} /> : null}
        </Deferred>
    );
}

function PlanView({ plan }: { plan: Plan }) {
    const t = useTranslate();
    const monthsShort = useTranslationList('common.months_short');
    const threshold = plan.threshold === null ? null : { band: plan.threshold.label, value: plan.threshold.value };
    const columns: DataTableColumn[] = [
        { key: 'lga', header: t('dashboard.plan.lga') },
        { key: 'overall', header: t('dashboard.plan.overall'), align: 'end' },
        { key: 'weakest', header: t('dashboard.plan.weakest') },
        { key: 'score', header: t('dashboard.plan.score'), align: 'end' },
        { key: 'action', header: t('dashboard.plan.action') },
        { key: 'owner', header: t('dashboard.plan.owner') },
        { key: 'review', header: t('dashboard.plan.review') },
    ];

    return (
        <div className="flex flex-col gap-4.5">
            <Panel labelledBy="plan-actions" className="overflow-hidden">
                <PanelHeader
                    id="plan-actions"
                    title={t('dashboard.plan.title')}
                    description={plan.round === null || threshold === null ? t('dashboard.plan.description_none') : t('dashboard.plan.description', { round: plan.round, ...threshold })}
                />
                <p className="px-5 pt-1 text-[12px] text-ink-2">{t('dashboard.plan.read_only')}</p>
                <div className="mt-2">
                    <DataTable caption={t('dashboard.plan.title')} captionHidden columns={columns} empty={<EmptyState title={t('dashboard.plan.none')} />}>
                        {plan.actions.map((row) => (
                            <tr key={`${row.lgaId}-${row.dimension}`} className="border-b border-paper last:border-b-0">
                                <th scope="row" className="px-3 py-2 text-left font-medium whitespace-nowrap">
                                    {row.name}
                                </th>
                                <td className="px-3 py-2 text-right">
                                    <ScoreTag score={row.overall} />
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">{t(`enums.dimension.${row.dimension}`)}</td>
                                <HeatCell score={row.score} />
                                <td className="min-w-56 px-3 py-2">
                                    {row.action}
                                    {row.stored && <span className="ml-2 inline-block rounded-full bg-wash px-2 py-0.5 text-[11.5px] font-semibold text-ink-2">{t('dashboard.plan.saved')}</span>}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">{row.owner}</td>
                                <td className="px-3 py-2 whitespace-nowrap tabular-nums">
                                    {row.reviewDate !== null
                                        ? monthsShort.length === 12
                                            ? formatDate(row.reviewDate, monthsShort)
                                            : row.reviewDate
                                        : t('dashboard.plan.review_after', { round: row.reviewRound ?? '' })}
                                </td>
                            </tr>
                        ))}
                    </DataTable>
                </div>
            </Panel>

            <Panel labelledBy="plan-clear">
                <PanelHeader id="plan-clear" title={t('dashboard.plan.clear_title')} {...(threshold === null ? {} : { description: t('dashboard.plan.clear_description', threshold) })} />
                {plan.clear.length === 0 ? (
                    <p className="px-5 pt-2 pb-4 text-[13px] text-ink-2">{t('dashboard.plan.clear_none')}</p>
                ) : (
                    <ul aria-label={t('dashboard.plan.clear_list')} className="flex flex-wrap gap-2 px-5 pt-2 pb-4">
                        {plan.clear.map((lga) => (
                            <li key={lga.lgaId} className="flex items-center gap-2 rounded-md border border-rule px-2.5 py-1 text-[13px]">
                                <span className="font-medium">{lga.name}</span>
                                <ScoreTag score={lga.overall} />
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <div className="grid grid-cols-1 items-start gap-4.5 lg:grid-cols-2">
                <Panel labelledBy="plan-types" className="min-w-0 overflow-hidden">
                    <PanelHeader id="plan-types" title={t('dashboard.plan.types_title')} description={t('dashboard.plan.types_description')} />
                    <div className="mt-2">
                        <DataTable
                            caption={t('dashboard.plan.types_title')}
                            captionHidden
                            columns={[
                                { key: 'type', header: t('dashboard.plan.type') },
                                { key: 'count', header: t('dashboard.plan.count'), align: 'end' },
                                { key: 'owner', header: t('dashboard.plan.owner') },
                            ]}
                        >
                            {plan.summary.map((type) => (
                                <tr key={type.dimension} className="border-b border-paper last:border-b-0">
                                    <th scope="row" className="min-w-48 px-3 py-2 text-left font-normal">
                                        {type.action}
                                        <span className="block text-[11.5px] text-ink-2">{t('dashboard.plan.for_dimension', { dimension: t(`enums.dimension.${type.dimension}`) })}</span>
                                    </th>
                                    <td className="px-3 py-2 text-right font-semibold tabular-nums">{formatCount(type.count)}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{type.owner}</td>
                                </tr>
                            ))}
                        </DataTable>
                    </div>
                </Panel>

                <Panel labelledBy="plan-meanings" className="min-w-0 overflow-hidden">
                    <PanelHeader id="plan-meanings" title={t('dashboard.plan.meanings_title')} description={t('dashboard.plan.meanings_description')} />
                    <div className="mt-2">
                        <DataTable
                            caption={t('dashboard.plan.meanings_title')}
                            captionHidden
                            columns={[
                                { key: 'dimension', header: t('dashboard.plan.dimension') },
                                { key: 'response', header: t('dashboard.plan.response') },
                            ]}
                        >
                            {plan.summary.map((type) => (
                                <tr key={type.dimension} className="border-b border-paper last:border-b-0">
                                    <th scope="row" className="min-w-40 px-3 py-2 text-left">
                                        {t(`enums.dimension.${type.dimension}`)}
                                        <span className="block text-[11.5px] font-normal text-ink-2">{type.meaning}</span>
                                    </th>
                                    <td className="min-w-48 px-3 py-2">{type.action}</td>
                                </tr>
                            ))}
                        </DataTable>
                    </div>
                </Panel>
            </div>
        </div>
    );
}
