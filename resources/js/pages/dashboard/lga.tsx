import { Deferred, router, usePage } from '@inertiajs/react';
import { useAnnounce } from '@/components/Announcer';
import LgaGrid from '@/components/LgaGrid';
import LgaTable from '@/components/LgaTable';
import Panel from '@/components/Panel';
import Skeleton from '@/components/Skeleton';
import { formatCount } from '@/lib/format';
import { useTranslate, type Translate } from '@/lib/i18n';
import type { LgaProps } from '@/types/dashboard';
import type { FilterOptions, Filters, Summary } from '@/types/shell';

/**
 * LGA view (ARCHITECTURE.md §9.2.3): the tile grid (every LGA; a tile toggles the LGA filter) and
 * "All four reports by local government". The title, filters and tabs come from AppShell.
 */
export default function Lga({ rounds, tiles, lgas }: LgaProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const { url, props } = usePage();
    const path = url.split('?')[0] ?? url;
    const filters = props.filters;

    const toggle = (code: string) => {
        if (filters === undefined) {
            return;
        }
        const next: Filters = { ...filters, lga: filters.lga === code ? null : code };
        const query = Object.fromEntries(Object.entries(next).flatMap(([key, value]) => (value === null ? [] : [[key, String(value)]])));
        // In place, as the filter bar does: the tile keeps focus and the result is announced.
        router.get(path, query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (page) => {
                const summary = page.props.summary;
                if (isSummary(summary)) {
                    announce(
                        summary.facilities === 0
                            ? t('dashboard.filters.announce_none')
                            : t('dashboard.filters.announce', { count: formatCount(summary.facilities), average: summary.overall.display }),
                    );
                }
            },
        });
    };

    return (
        <div className="flex flex-col">
            <LgaGrid lgas={tiles} selected={filters?.lga ?? null} onToggle={toggle} />

            <h2 id="lga-table" className="mt-6.5 mb-2.75 text-[13px] text-ink-2">
                {t('dashboard.lga.table_title')}
            </h2>
            <Panel labelledBy="lga-table" className="overflow-hidden">
                <Deferred data="lgas" fallback={<Skeleton className="h-[480px] w-full" />}>
                    {lgas !== undefined && <LgaTable lgas={lgas} rounds={rounds} caption={caption(t, rounds, filters, props.filterOptions)} />}
                </Deferred>
            </Panel>
        </div>
    );
}

function isSummary(value: unknown): value is Summary {
    return typeof value === 'object' && value !== null && 'facilities' in value && 'overall' in value;
}

/** "All four reports by local government: Q2 2026, Zaria, primary level, public facilities." */
function caption(t: Translate, rounds: LgaProps['rounds'], filters: Filters | undefined, options: FilterOptions | undefined): string {
    const parts = rounds.map((round) => t('dashboard.lga.scope_round', { quarter: round.quarter, year: round.year }));
    const lga = options?.lgas.find((option) => option.code === filters?.lga)?.name;
    const level = options?.levels.find((option) => option.value === filters?.level)?.label;
    const owner = options?.owners.find((option) => option.value === filters?.owner)?.label;
    if (lga !== undefined) {
        parts.push(t('dashboard.lga.scope_lga', { lga }));
    }
    if (level !== undefined) {
        parts.push(t('dashboard.lga.scope_level', { level }));
    }
    if (owner !== undefined) {
        parts.push(t('dashboard.lga.scope_owner', { owner }));
    }
    if (lga === undefined && level === undefined && owner === undefined) {
        parts.push(t('dashboard.lga.scope_all'));
    }

    return t('dashboard.lga.caption', { scope: parts.join(', ') });
}
