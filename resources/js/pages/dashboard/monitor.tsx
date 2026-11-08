import { Deferred, usePage } from '@inertiajs/react';
import DataTable, { type DataTableColumn } from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import OwnerSplit from '@/components/OwnerSplit';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import RankedBars from '@/components/RankedBars';
import ScoreTag from '@/components/ScoreTag';
import Skeleton from '@/components/Skeleton';
import { formatCount, formatDate } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { AssessmentWindow, Coverage, GroupScores, MonitorProps, SourceCount } from '@/types/dashboard';

/**
 * Monitor (ARCHITECTURE.md §9.2.4): the overall quality score of all facilities beside Public vs
 * Private (the Home component; D-75), facilities visited per
 * local government against the state mean, coverage by level, each round's assessment window, and
 * the selection's assessments by source (ODK Central, KoboToolbox). Every block is deferred; the
 * title, filters and tabs come from PublicLayout.
 */
export default function Monitor({ owners, coverage, windows, sources, overall }: MonitorProps) {
    const t = useTranslate();
    const { filters, auth } = usePage().props;
    const owner = filters?.owner ?? null;
    // The counts by source are the admin preview's only (D-81): the server sends them to a
    // signed-in administrator alone, so a visitor's page has no panel waiting for them.
    const showSources = auth.user !== null;
    // All facilities in view, public and private together; the Owner filter narrows it to one.
    const all: GroupScores | undefined =
        overall === undefined
            ? undefined
            : {
                  facilities: overall.summary.facilitiesVisited,
                  availability: overall.dimensions.availability,
                  consistency: overall.dimensions.consistency,
                  validity: overall.dimensions.validity,
                  overall: overall.summary.overall,
              };
    const allTitle = owner === 'public' || owner === 'private' ? t(`dashboard.monitor.overall_${owner}`) : t('dashboard.monitor.overall_all');

    return (
        <div className="flex flex-col">
            <h2 id="monitor-owners" className="flex items-center gap-4 text-md font-semibold tracking-[-0.015em] text-ink after:h-px after:flex-1 after:bg-rule-strong">
                {t('dashboard.monitor.overall_title')}
            </h2>
            <p className="mt-1 mb-3.5 max-w-[72ch] text-sm text-ink-2">
                {owner === 'public' || owner === 'private' ? t('dashboard.monitor.overall_description_owner', { owner: t(`dashboard.visuals.owners.${owner}`) }) : t('dashboard.monitor.overall_description')}
            </p>
            <Panel labelledBy="monitor-owners" className="overflow-hidden">
                <Deferred data={['owners', 'overall']} fallback={<Skeleton className="h-[188px] w-full" />}>
                    {owners !== undefined && <OwnerSplit split={owners} {...(all !== undefined ? { all: { title: allTitle, scores: all } } : {})} />}
                </Deferred>
            </Panel>

            <div className="mt-5 grid grid-cols-1 items-start gap-5 lg:grid-cols-2">
                <Panel labelledBy="monitor-lgas">
                    <PanelHeader id="monitor-lgas" title={t('dashboard.monitor.lgas_title')} description={t('dashboard.monitor.lgas_description')} />
                    <Deferred data="coverage" fallback={<Skeleton className="m-5 h-[520px]" />}>
                        {coverage !== undefined && <LgaBars coverage={coverage} />}
                    </Deferred>
                </Panel>

                <div className="flex min-w-0 flex-col gap-5">
                    <Panel labelledBy="monitor-levels">
                        <PanelHeader id="monitor-levels" title={t('dashboard.monitor.levels_title')} description={t('dashboard.monitor.levels_description')} />
                        <Deferred data="coverage" fallback={<Skeleton className="m-5 h-[120px]" />}>
                            {coverage !== undefined && <LevelBars coverage={coverage} />}
                        </Deferred>
                    </Panel>

                    <Panel labelledBy="monitor-window" className="overflow-hidden">
                        <PanelHeader id="monitor-window" title={t('dashboard.monitor.window_title')} description={t('dashboard.monitor.window_description')} />
                        <div className="mt-2">
                            <Deferred data="windows" fallback={<Skeleton className="m-5 h-[120px]" />}>
                                {windows !== undefined && <WindowTable windows={windows} />}
                            </Deferred>
                        </div>
                    </Panel>

                    {showSources && (
                        <Panel labelledBy="monitor-sources" className="overflow-hidden">
                            <PanelHeader id="monitor-sources" title={t('dashboard.monitor.sources_title')} description={t('dashboard.monitor.sources_description')} />
                            <div className="mt-2">
                                <Deferred data="sources" fallback={<Skeleton className="m-5 h-[80px]" />}>
                                    {sources !== undefined && <SourceTable sources={sources} />}
                                </Deferred>
                            </div>
                        </Panel>
                    )}
                </div>
            </div>
        </div>
    );
}

function LgaBars({ coverage }: { coverage: Coverage }) {
    const t = useTranslate();

    return (
        <RankedBars
            label={t('dashboard.monitor.lgas_list')}
            empty={t('dashboard.monitor.lgas_none')}
            marker={{ value: coverage.stateMean, label: t('dashboard.monitor.state_mean', { mean: coverage.stateMeanDisplay }) }}
            items={coverage.perLga.map((lga) => ({
                key: lga.lgaId,
                label: lga.name,
                value: lga.facilities,
                valueText: t('dashboard.monitor.visited', { count: formatCount(lga.facilities) }),
            }))}
        />
    );
}

function LevelBars({ coverage }: { coverage: Coverage }) {
    const t = useTranslate();

    return (
        <RankedBars
            label={t('dashboard.monitor.levels_list')}
            empty={t('dashboard.monitor.levels_none')}
            items={coverage.byLevel.map((level) => ({
                key: level.level,
                label: t(`enums.facility_level.${level.level}`),
                value: level.facilities,
                valueText: t('dashboard.monitor.visited', { count: formatCount(level.facilities) }),
                detail: <ScoreTag score={level.overall} />,
            }))}
        />
    );
}

function SourceTable({ sources }: { sources: SourceCount[] }) {
    const t = useTranslate();
    const columns: DataTableColumn[] = [
        { key: 'source', header: t('dashboard.monitor.source') },
        { key: 'assessments', header: t('dashboard.monitor.assessments'), align: 'end' },
    ];

    return (
        <DataTable caption={t('dashboard.monitor.sources_title')} captionHidden columns={columns} empty={<EmptyState title={t('dashboard.monitor.sources_none')} />}>
            {sources.map((source) => (
                <tr key={source.key ?? 'unknown'} className="border-b border-paper last:border-b-0">
                    <th scope="row" className="px-3 py-2 text-left font-medium">
                        {source.key === null ? t('dashboard.monitor.source_unknown') : source.name}
                    </th>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCount(source.assessments)}</td>
                </tr>
            ))}
        </DataTable>
    );
}

function WindowTable({ windows }: { windows: AssessmentWindow[] }) {
    const t = useTranslate();
    const monthsShort = useTranslationList('common.months_short');
    const date = (value: string) => (monthsShort.length === 12 ? formatDate(value, monthsShort) : value);
    const columns: DataTableColumn[] = [
        { key: 'round', header: t('dashboard.monitor.round') },
        { key: 'window', header: t('dashboard.monitor.window') },
        { key: 'first', header: t('dashboard.monitor.first_visit') },
        { key: 'last', header: t('dashboard.monitor.last_visit') },
        { key: 'assessments', header: t('dashboard.monitor.assessments'), align: 'end' },
    ];

    return (
        <DataTable caption={t('dashboard.monitor.window_title')} captionHidden columns={columns} empty={<EmptyState title={t('dashboard.monitor.windows_none')} />}>
            {windows.map((window) => (
                <tr key={window.roundId} className="border-b border-paper last:border-b-0">
                    <th scope="row" className="px-3 py-2 text-left font-medium whitespace-nowrap">
                        {window.label}
                    </th>
                    <td className="min-w-32 px-3 py-2 tabular-nums">{t('dashboard.monitor.window_range', { start: date(window.windowStart), end: date(window.windowEnd) })}</td>
                    <td className="px-3 py-2 whitespace-nowrap tabular-nums">{window.firstVisit === null ? t('dashboard.monitor.no_visit') : date(window.firstVisit)}</td>
                    <td className="px-3 py-2 whitespace-nowrap tabular-nums">{window.lastVisit === null ? t('dashboard.monitor.no_visit') : date(window.lastVisit)}</td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCount(window.assessments)}</td>
                </tr>
            ))}
        </DataTable>
    );
}
