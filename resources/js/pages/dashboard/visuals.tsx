import { Deferred, router, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import MonthFigure from '@/components/MonthFigure';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import RankedBars from '@/components/RankedBars';
import ScoreGroupFigure from '@/components/ScoreGroupFigure';
import ScoreTag from '@/components/ScoreTag';
import SegmentedControl from '@/components/SegmentedControl';
import Skeleton from '@/components/Skeleton';
import { useTranslate } from '@/lib/i18n';
import { sortRows } from '@/lib/sortRows';
import type { LgaRow, MonthSlots, OwnerView, TrendPoint, VisualsProps } from '@/types/dashboard';

const VIEWS: OwnerView[] = ['all', 'public', 'private'];
const PERIODS = ['monthly', 'quarterly'] as const;
type Period = (typeof PERIODS)[number];
const SLOTS = [1, 2, 3] as const;
const MEASURES = ['overall', 'availability', 'consistency', 'validity'] as const;

/**
 * Visuals (ARCHITECTURE.md §9.2.5): an Overall / Public / Private view (?owner_view=, apart from
 * the global owner filter, and locked to it while that filter is set), a Month / Quarter view (D-75):
 * the three month charts, or every quarter and the quarter in view month by month, and the four
 * LGA rankings. Both blocks are deferred, so the page paints before the charts; changing
 * the view reloads only them.
 */
export default function Visuals({ ownerView, monthSlots, quarters, lgas }: VisualsProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const { url, props } = usePage();
    const lockedId = useId();
    const [period, setPeriod] = useState<Period>('monthly');
    const year = props.filters?.year ?? null;
    const quarter = props.filters?.quarter ?? null;
    const [path, search = ''] = url.split('?');
    const locked = ownerView.locked !== null;

    const changeView = (view: OwnerView) => {
        const query = new URLSearchParams(search);
        if (view === 'all') {
            query.delete('owner_view');
        } else {
            query.set('owner_view', view);
        }
        router.get(path ?? url, Object.fromEntries(query), {
            only: ['ownerView', 'monthSlots', 'quarters', 'lgas'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => announce(t('dashboard.visuals.announce', { view: t(`dashboard.visuals.views.${view}`) })),
        });
    };

    const ranksTitle = ownerView.value === 'all' ? t('dashboard.visuals.ranks_title') : t('dashboard.visuals.ranks_title_owner', { owner: t(`dashboard.visuals.owners.${ownerView.value}`) });

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                <SegmentedControl
                    label={t('dashboard.visuals.view')}
                    value={ownerView.value}
                    onChange={changeView}
                    disabled={locked}
                    {...(locked ? { describedBy: lockedId } : {})}
                    options={VIEWS.map((view) => ({ value: view, label: t(`dashboard.visuals.views.${view}`) }))}
                />
                {ownerView.locked !== null && (
                    <p id={lockedId} className="max-w-[70ch] text-xs text-ink-2">
                        {t('dashboard.visuals.locked', { owner: t(`dashboard.visuals.views.${ownerView.locked}`) })}
                    </p>
                )}
                <SegmentedControl
                    label={t('dashboard.visuals.period')}
                    value={period}
                    onChange={setPeriod}
                    options={PERIODS.map((value) => ({ value, label: t(`dashboard.visuals.periods.${value}`) }))}
                />
            </div>

            {period === 'monthly' ? (
                <Panel labelledBy="visuals-months">
                    <PanelHeader id="visuals-months" title={t('dashboard.visuals.months_title')} description={t('dashboard.visuals.months_description')} />
                    <Deferred data="monthSlots" fallback={<Skeleton className="m-5 h-[300px]" />}>
                        {monthSlots !== undefined && <MonthFigures monthSlots={monthSlots} />}
                    </Deferred>
                </Panel>
            ) : (
                <>
                    <Panel labelledBy="visuals-quarters">
                        <h2 id="visuals-quarters" className="sr-only">
                            {t('dashboard.visuals.quarters_title')}
                        </h2>
                        <Deferred data="quarters" fallback={<Skeleton className="m-5 h-[300px]" />}>
                            {quarters !== undefined && <QuarterFigure quarters={quarters} />}
                        </Deferred>
                    </Panel>
                    {year !== null && quarter !== null && (
                        <Panel labelledBy="visuals-quarter-months">
                            <h2 id="visuals-quarter-months" className="sr-only">
                                {t('dashboard.visuals.quarter_months_title', { quarter, year })}
                            </h2>
                            <Deferred data="monthSlots" fallback={<Skeleton className="m-5 h-[300px]" />}>
                                {monthSlots !== undefined && <QuarterMonths monthSlots={monthSlots} year={year} quarter={quarter} />}
                            </Deferred>
                        </Panel>
                    )}
                </>
            )}

            <Panel labelledBy="visuals-ranks">
                <PanelHeader id="visuals-ranks" title={ranksTitle} description={t('dashboard.visuals.ranks_description')} />
                <Deferred data="lgas" fallback={<Skeleton className="m-5 h-[600px]" />}>
                    {lgas !== undefined && <Rankings lgas={lgas} />}
                </Deferred>
            </Panel>
        </div>
    );
}

function MonthFigures({ monthSlots }: { monthSlots: MonthSlots }) {
    return (
        <div className="grid grid-cols-1 gap-px bg-paper lg:grid-cols-3">
            {SLOTS.map((slot) => (
                <div key={slot} className="min-w-0 bg-panel">
                    <MonthFigure slot={slot} points={monthSlots.points.filter((point) => point.slot === slot)} takeaway={monthSlots.takeaways[String(slot) as '1' | '2' | '3']} />
                </div>
            ))}
        </div>
    );
}

function QuarterFigure({ quarters }: { quarters: TrendPoint[] }) {
    const t = useTranslate();

    return (
        <ScoreGroupFigure
            title={t('dashboard.visuals.quarters_title')}
            description={quarters.length === 0 ? t('dashboard.visuals.no_quarters') : t('dashboard.visuals.quarters_description')}
            rowHeader={t('dashboard.visuals.quarter')}
            rows={quarters.map((point) => ({ ...point, key: point.roundId }))}
        />
    );
}

/** The selected quarter, one group per month: the month figures' points for that quarter only. */
function QuarterMonths({ monthSlots, year, quarter }: { monthSlots: MonthSlots; year: number; quarter: number }) {
    const t = useTranslate();
    const months = monthSlots.points.filter((point) => point.year === year && point.quarter === quarter).sort((a, b) => a.slot - b.slot);

    return (
        <ScoreGroupFigure
            title={t('dashboard.visuals.quarter_months_title', { quarter, year })}
            description={t('dashboard.visuals.quarter_months_description')}
            rowHeader={t('dashboard.visuals.month')}
            rows={months.map((point) => ({ ...point, key: point.month }))}
        />
    );
}

function Rankings({ lgas }: { lgas: LgaRow[] }) {
    const t = useTranslate();
    const name = (measure: (typeof MEASURES)[number]) => (measure === 'overall' ? t('dashboard.visuals.overall') : t(`enums.dimension.${measure}`));

    return (
        <div className="grid grid-cols-1 gap-x-4.5 lg:grid-cols-2">
            {MEASURES.map((measure) => (
                <section key={measure} aria-labelledby={`rank-${measure}`} className="min-w-0 pt-3">
                    <h3 id={`rank-${measure}`} className="px-5 text-sm font-semibold">
                        {name(measure)}
                    </h3>
                    <RankedBars
                        label={t('dashboard.visuals.rank_list', { measure: name(measure) })}
                        empty={t('dashboard.visuals.ranks_none')}
                        max={100}
                        // Highest first, no data last (lib/sortRows only compares).
                        items={sortRows(lgas, (lga) => lga[measure].value, 'desc').map((lga) => ({
                            key: lga.lgaId,
                            label: lga.name,
                            value: lga[measure].value,
                            tone: lga[measure].band,
                            detail: <ScoreTag score={lga[measure]} />,
                        }))}
                    />
                </section>
            ))}
        </div>
    );
}
