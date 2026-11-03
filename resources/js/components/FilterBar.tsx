import { useEffect, useId, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { useAnnounce } from '@/components/Announcer';
import { formatCount } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { FilterKey, FilterOptions, Filters, Summary } from '@/types/shell';

export type FilterBarProps = {
    filters: Filters;
    options: FilterOptions;
    /** The page's path, without the query string. */
    path: string;
};

type Values = Record<FilterKey, string>;

const KEYS: FilterKey[] = ['year', 'quarter', 'month', 'lga', 'level', 'owner'];
const DEBOUNCE_MS = 300;

const toValues = (filters: Filters): Values => ({
    year: filters.year === null ? '' : String(filters.year),
    // A month stands for its quarter (D-75): one Duration in the URL, never both.
    quarter: filters.quarter === null || filters.month !== null ? '' : String(filters.quarter),
    month: filters.month === null ? '' : String(filters.month),
    lga: filters.lga ?? '',
    level: filters.level ?? '',
    owner: filters.owner ?? '',
});

/** The Duration select's value: "q2" for a quarter, "m5" for a month, "" for every quarter. */
const durationOf = (values: Values): string => (values.month !== '' ? `m${values.month}` : values.quarter !== '' ? `q${values.quarter}` : '');

/** The quarter the Duration falls in, "" when none is chosen. */
const QUARTER_OF_MONTH: Record<string, string> = { 1: '1', 2: '1', 3: '1', 4: '2', 5: '2', 6: '2', 7: '3', 8: '3', 9: '3', 10: '4', 11: '4', 12: '4' };
const quarterOf = (values: Values): string => (values.month !== '' ? (QUARTER_OF_MONTH[values.month] ?? '') : values.quarter);

const toQuery = (values: Values): Record<string, string> => Object.fromEntries(KEYS.filter((key) => values[key] !== '').map((key) => [key, values[key]]));

function isSummary(value: unknown): value is Summary {
    return typeof value === 'object' && value !== null && 'facilities' in value && 'overall' in value;
}

/**
 * The global filters (ARCHITECTURE.md §9.1): native selects with visible labels. Duration (D-75) is
 * one select over a quarter or one month of a quarter with a round, so the view is never both. A change waits
 * 300 ms (arrow keys change a select once per press), then reloads the page's props in place:
 * the URL is replaced, scroll and focus stay where they are, and the announcer reads out how
 * many facilities and the average now in view. The options come from the server.
 */
export default function FilterBar({ filters, options, path }: FilterBarProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const monthNames = useTranslationList('common.months');
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
    // Duration is usable before a year is chosen: it then offers the latest year's quarters and
    // months, and choosing one sets that year too (a quarter or a month needs a year).
    const latestYear = [...years].sort((a, b) => b - a)[0];
    const durationYear = values.year !== '' ? values.year : latestYear === undefined ? '' : String(latestYear);
    const quarters = options.rounds.filter((round) => String(round.year) === durationYear).map((round) => round.quarter).sort();

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
        if (key === 'year' && !options.rounds.some((round) => String(round.year) === value && String(round.quarter) === quarterOf(next))) {
            next.quarter = '';
            next.month = '';
        }
        setValues(next);
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => visit(next), DEBOUNCE_MS);
    };

    const anySet = KEYS.some((key) => values[key] !== '');
    const changeDuration = (value: string) => {
        // "q2", "m5" or "": set the one and clear the other, then the usual debounced visit.
        const kind = value.charAt(0);
        const number = value.slice(1);
        const next = { ...values, year: value === '' ? values.year : durationYear, quarter: kind === 'q' ? number : '', month: kind === 'm' ? number : '' };
        setValues(next);
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => visit(next), DEBOUNCE_MS);
    };
    const select = 'control w-full min-h-9 sm:w-auto sm:min-w-[128px]';
    const label = 'text-2xs font-medium text-ink-2';

    return (
        <form role="search" aria-label={t('dashboard.filters.label')} className="grid grid-cols-2 gap-x-2.5 gap-y-2.5 pb-4 sm:flex sm:flex-wrap sm:items-end sm:gap-3" onSubmit={(event) => event.preventDefault()}>
            <div className="flex min-w-0 flex-col gap-1">
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
            <div className="flex min-w-0 flex-col gap-1">
                <label htmlFor={`${id}-duration`} className={label}>
                    {t('dashboard.filters.duration')}
                </label>
                <select
                    id={`${id}-duration`}
                    className={select}
                    value={durationOf(values)}
                    onChange={(event) => changeDuration(event.target.value)}
                >
                    <option value="">{t('dashboard.filters.all_quarters')}</option>
                    {quarters.length > 0 && (
                        <optgroup label={t('dashboard.filters.duration_quarters')}>
                            {quarters.map((quarter) => (
                                <option key={quarter} value={`q${quarter}`}>
                                    {t('dashboard.filters.quarter_option', { quarter })}
                                </option>
                            ))}
                        </optgroup>
                    )}
                    {quarters.length > 0 && (
                        <optgroup label={t('dashboard.filters.duration_months')}>
                            {quarters.flatMap((quarter) =>
                                [1, 2, 3].map((slot) => {
                                    const month = (quarter - 1) * 3 + slot;

                                    return (
                                        <option key={month} value={`m${month}`}>
                                            {t('dashboard.filters.month_option', { month: monthNames[month - 1] ?? String(month), quarter })}
                                        </option>
                                    );
                                }),
                            )}
                        </optgroup>
                    )}
                </select>
            </div>
            <div className="flex min-w-0 flex-col gap-1">
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
            <div className="flex min-w-0 flex-col gap-1">
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
            <div className="col-span-2 flex min-w-0 flex-col gap-1 sm:col-span-1">
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
                    className="btn btn-quiet btn-sm self-end"
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
