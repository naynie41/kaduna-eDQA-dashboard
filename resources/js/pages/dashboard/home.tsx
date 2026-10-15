import { Deferred, Link, usePage } from '@inertiajs/react';
import EmptyState from '@/components/EmptyState';
import HomeHero from '@/components/HomeHero';
import OwnerSplit from '@/components/OwnerSplit';
import Panel from '@/components/Panel';
import Skeleton from '@/components/Skeleton';
import TrendFigure from '@/components/TrendFigure';
import WeakestList from '@/components/WeakestList';
import { useTranslate } from '@/lib/i18n';
import type { HomeProps } from '@/types/dashboard';

/**
 * Home (ARCHITECTURE.md §9.2.1): the hero, Public vs Private, the quarter trend and the weakest
 * LGAs (or wards). The title, filters and tabs come from AppShell. Every number is the server's.
 */
export default function Home({ hero, dimensions, owners, trend, weakest }: HomeProps) {
    const t = useTranslate();
    const { url, props } = usePage();
    const path = url.split('?')[0] ?? url;
    const lgaCode = props.filters?.lga ?? null;
    const lgaName = props.filterOptions?.lgas.find((lga) => lga.code === lgaCode)?.name ?? null;
    const filtered = props.filters !== undefined && Object.values(props.filters).some((value) => value !== null);

    if (hero.facilitiesVisited === 0) {
        return (
            <Panel>
                <EmptyState
                    title={t('dashboard.home.empty_title')}
                    description={t('dashboard.home.empty_description')}
                    action={
                        filtered ? (
                            <Link href={path} className="text-[13.5px] text-deep-2 underline underline-offset-2">
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
            <HomeHero hero={hero} dimensions={dimensions} />

            <h2 id="home-owners" className="mt-6.5 mb-2.75 text-[13px] text-ink-2">
                {t('dashboard.home.owners_title')}
            </h2>
            <Panel labelledBy="home-owners" className="overflow-hidden">
                <Deferred data="owners" fallback={<Skeleton className="h-[188px] w-full" />}>
                    {owners !== undefined && <OwnerSplit split={owners} />}
                </Deferred>
            </Panel>

            <div className="mt-4.5 grid items-start gap-4.5 lg:grid-cols-[1.15fr_1fr]">
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
