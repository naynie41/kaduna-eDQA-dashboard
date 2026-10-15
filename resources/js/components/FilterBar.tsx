import { useEffect, useId, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { useAnnounce } from '@/components/Announcer';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { FilterKey, FilterOptions, Filters, Summary } from '@/types/shell';

export type FilterBarProps = {
    filters: Filters;
    options: FilterOptions;
    /** The page's path, without the query string. */
    path: string;
};

type Values = Record<FilterKey, string>;

const KEYS: FilterKey[] = ['year', 'quarter', 'lga', 'level', 'owner'];
const DEBOUNCE_MS = 300;

const toValues = (filters: Filters): Values => ({
    year: filters.year === null ? '' : String(filters.year),
    quarter: filters.quarter === null ? '' : String(filters.quarter),
    lga: filters.lga ?? '',
    level: filters.level ?? '',
    owner: filters.owner ?? '',
});

const toQuery = (values: Values): Record<string, string> => Object.fromEntries(KEYS.filter((key) => values[key] !== '').map((key) => [key, values[key]]));

function isSummary(value: unknown): value is Summary {
    return typeof value === 'object' && value !== null && 'facilities' in value && 'overall' in value;
}

/**
 * The global filters (ARCHITECTURE.md §9.1): native selects with visible labels. A change waits
 * 300 ms (arrow keys change a select once per press), then reloads the page's props in place:
 * the URL is replaced, scroll and focus stay where they are, and the announcer reads out how
 * many facilities and the average now in view. The options come from the server.
 */
export default function FilterBar({ filters, options, path }: FilterBarProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const id = useId();
    const [values, setValues] = useState<Values>(() => toValues(filters));
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const yearSelect = useRef<HTMLSelectElement>(null);

    // The server's resolved filters win after every visit (a cleaned URL, Clear filters): when
    // they change, the selects follow (React's "adjust state when a prop changes").
    const filtersKey = JSON.stringify(filters);
    const [seenKey, setSeenKey] = useState(filtersKey);
    if (seenKey !== filtersKey) {
        setSeenKey(filtersKey);
        setValues(toValues(filters));
    }

    useEffect(
        () => () => {
            if (timer.current !== null) {
                clearTimeout(timer.current);
            }
        },
        [],
    );

    const years = [...new Set(options.rounds.map((round) => round.year))];
    const quarters = options.rounds.filter((round) => String(round.year) === values.year).map((round) => round.quarter).sort();

    const visit = (next: Values) => {
        router.get(path, toQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (page) => {
                const summary = page.props.summary;
                if (!isSummary(summary)) {
                    return;
                }
                announce(
                    summary.facilities === 0
                        ? t('dashboard.filters.announce_none')
                        : t('dashboard.filters.announce', { count: formatCount(summary.facilities), average: summary.overall.display }),
                );
            },
        });
    };

    const change = (key: FilterKey, value: string) => {
        const next = { ...values, [key]: value };
        if (key === 'year' && !options.rounds.some((round) => String(round.year) === value && String(round.quarter) === next.quarter)) {
            next.quarter = '';
        }
        setValues(next);
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => visit(next), DEBOUNCE_MS);
    };

    const anySet = KEYS.some((key) => values[key] !== '');
    const quarterHint = `${id}-quarter-hint`;
    const select = 'min-w-[104px] rounded-md border border-rule bg-panel px-2 py-1.5 text-[13px] text-ink disabled:bg-paper disabled:text-ink-2';
    const label = 'text-[11px] text-ink-2';

    return (
        <form role="search" aria-label={t('dashboard.filters.label')} className="flex flex-wrap items-end gap-2 pb-3" onSubmit={(event) => event.preventDefault()}>
            <div className="flex flex-col gap-0.5">
                <label htmlFor={`${id}-year`} className={label}>
                    {t('dashboard.filters.year')}
                </label>
                <select id={`${id}-year`} ref={yearSelect} className={select} value={values.year} onChange={(event) => change('year', event.target.value)}>
                    <option value="">{t('dashboard.filters.all_years')}</option>
                    {years.map((year) => (
                        <option key={year} value={String(year)}>
                            {year}
                        </option>
                    ))}
                </select>
            </div>
            <div className="flex flex-col gap-0.5">
                <label htmlFor={`${id}-quarter`} className={label}>
                    {t('dashboard.filters.quarter')}
                </label>
                <select
                    id={`${id}-quarter`}
                    className={select}
                    value={values.quarter}
                    disabled={values.year === ''}
                    aria-describedby={values.year === '' ? quarterHint : undefined}
                    onChange={(event) => change('quarter', event.target.value)}
                >
                    <option value="">{t('dashboard.filters.all_quarters')}</option>
                    {quarters.map((quarter) => (
                        <option key={quarter} value={String(quarter)}>
                            {t('dashboard.filters.quarter_option', { quarter })}
                        </option>
                    ))}
                </select>
                {values.year === '' && (
                    <span id={quarterHint} className="sr-only">
                        {t('dashboard.filters.quarter_needs_year')}
                    </span>
                )}
            </div>
            <div className="flex flex-col gap-0.5">
                <label htmlFor={`${id}-lga`} className={label}>
                    {t('dashboard.filters.lga')}
                </label>
                <select id={`${id}-lga`} className={select} value={values.lga} onChange={(event) => change('lga', event.target.value)}>
                    <option value="">{t('dashboard.filters.all_lgas')}</option>
                    {options.lgas.map((lga) => (
                        <option key={lga.code} value={lga.code}>
                            {lga.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="flex flex-col gap-0.5">
                <label htmlFor={`${id}-level`} className={label}>
                    {t('dashboard.filters.level')}
                </label>
                <select id={`${id}-level`} className={select} value={values.level} onChange={(event) => change('level', event.target.value)}>
                    <option value="">{t('dashboard.filters.all_levels')}</option>
                    {options.levels.map((level) => (
                        <option key={level.value} value={level.value}>
                            {level.label}
                        </option>
                    ))}
                </select>
            </div>
            <div className="flex flex-col gap-0.5">
                <label htmlFor={`${id}-owner`} className={label}>
                    {t('dashboard.filters.owner')}
                </label>
                <select id={`${id}-owner`} className={select} value={values.owner} onChange={(event) => change('owner', event.target.value)}>
                    <option value="">{t('dashboard.filters.all_owners')}</option>
                    {options.owners.map((owner) => (
                        <option key={owner.value} value={owner.value}>
                            {owner.label}
                        </option>
                    ))}
                </select>
            </div>
            {anySet && (
                <Link
                    href={path}
                    preserveScroll
                    preserveState
                    replace
                    className="mb-1.5 text-[13px] text-deep-2 underline underline-offset-2"
                    onSuccess={() => {
                        // The link goes away with the filters: put focus somewhere useful.
                        yearSelect.current?.focus();
                        announce(t('dashboard.filters.cleared'));
                    }}
                >
                    {t('dashboard.filters.clear')}
                </Link>
            )}
        </form>
    );
}
