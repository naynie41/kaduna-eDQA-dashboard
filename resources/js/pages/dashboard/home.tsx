import { Deferred, Link, usePage } from '@inertiajs/react';
import EmptyState from '@/components/EmptyState';
import HomeHero from '@/components/HomeHero';
import OwnerSplit from '@/components/OwnerSplit';
import Panel from '@/components/Panel';
import ScoreGroupFigure from '@/components/ScoreGroupFigure';
import Skeleton from '@/components/Skeleton';
import TrendFigure from '@/components/TrendFigure';
import WeakestList from '@/components/WeakestList';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { HomeProps, MonthSlotPoint } from '@/types/dashboard';

/**
 * Home (ARCHITECTURE.md §9.2.1): the hero, Public vs Private, the quarter in view month by month
 * (D-75), the quarter trend and the weakest LGAs (or wards). The title, filters and tabs come from AppShell. Every number is the server's.
 */
export default function Home({ hero, dimensions, owners, trend, weakest, months }: HomeProps) {
    const t = useTranslate();
    const monthNames = useTranslationList('common.months');
    const { url, props } = usePage();
    const path = url.split('?')[0] ?? url;
    const lgaCode = props.filters?.lga ?? null;
    const lgaName = props.filterOptions?.lgas.find((lga) => lga.code === lgaCode)?.name ?? null;
    const filtered = props.filters !== undefined && Object.values(props.filters).some((value) => value !== null);
    const month = props.filters?.month ?? null;
    const monthInView = month === null || props.filters?.year == null ? null : `${monthNames[month - 1] ?? month} ${props.filters.year}`;

    if (hero.facilitiesVisited === 0) {
        return (
            <Panel>
                <EmptyState
                    title={t('dashboard.home.empty_title')}
                    description={t('dashboard.home.empty_description')}
                    action={
                        filtered ? (
                            <Link href={path} className="text-sm text-deep-2 underline underline-offset-2">
                                {t('dashboard.filters.clear')}
                            </Link>
                        ) : undefined
                    }
                />
            </Panel>
        );
    }

    return (
        <div className="flex flex-col">
            <HomeHero hero={hero} dimensions={dimensions} month={monthInView} />

            <h2 id="home-owners" className="mt-9 mb-3.5 flex items-center gap-4 text-md font-semibold tracking-[-0.015em] text-ink after:h-px after:flex-1 after:bg-rule-strong">
                {t('dashboard.home.owners_title')}
            </h2>
            <Panel labelledBy="home-owners" className="overflow-hidden">
                <Deferred data="owners" fallback={<Skeleton className="h-[188px] w-full" />}>
                    {owners !== undefined && <OwnerSplit split={owners} />}
                </Deferred>
            </Panel>

            {/* Inertia needs a fallback element; nothing shows until the months arrive (none for several quarters). */}
            <Deferred data="months" fallback={<></>}>
                {months !== undefined && months.length > 0 && <QuarterMonths months={months} />}
            </Deferred>

            <div className="mt-5 grid items-start gap-5 lg:grid-cols-[1.15fr_1fr]">
                <Panel>
                    <Deferred data="trend" fallback={<Skeleton className="h-[300px] w-full" />}>
                        {trend !== undefined && <TrendFigure trend={trend} strongAt={hero.strongAt} />}
                    </Deferred>
                </Panel>
                <Deferred data="weakest" fallback={<Skeleton className="h-[300px] w-full" />}>
                    {weakest !== undefined && <WeakestList weakest={weakest} lgaName={lgaName} />}
                </Deferred>
            </div>
        </div>
    );
}

/** The quarter in view, one group per month: its three dimensions and its overall (D-75). */
function QuarterMonths({ months }: { months: MonthSlotPoint[] }) {
    const t = useTranslate();
    const first = months[0];
    if (first === undefined) {
        return null;
    }

    return (
        <Panel labelledBy="home-months" className="mt-5">
            <h2 id="home-months" className="sr-only">
                {t('dashboard.home.months_title', { quarter: first.quarter, year: first.year })}
            </h2>
            <ScoreGroupFigure
                title={t('dashboard.home.months_title', { quarter: first.quarter, year: first.year })}
                description={t('dashboard.home.months_description')}
                rowHeader={t('dashboard.home.month')}
                rows={months.map((point) => ({ ...point, key: point.month }))}
            />
        </Panel>
    );
}
