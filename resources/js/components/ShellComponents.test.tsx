import { act, fireEvent, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FilterBar from '@/components/FilterBar';
import SyncStatusBar from '@/components/SyncStatusBar';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { FilterOptions, Filters, ShellStatus, SyncStatus } from '@/types/shell';

const routerGet = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, get: (...args: unknown[]) => routerGet(...args) } };
});

const translations = {
    ...testTranslations,
    // The per-source status bar is the admin layout's (admin.sync).
    admin: {
        sync: {
            label: 'Data sources',
            not_configured: ':source is not configured',
            disabled: ':source is switched off',
            not_connected: ':source: no pull has finished yet',
            healthy: ':source: last pull :minutes min ago',
            stale: ':source: last successful pull :minutes min ago, more than :limit minutes',
            failed: ':source: last pull failed at :time (:error); last success :success',
            failed_no_error: ':source: last pull failed at :time; last success :success',
            auth_failed: ":source refused the portal's credentials at :time; it is not pulling until they are fixed. Last success :success",
            never_succeeded: 'never',
            counts: ':assessments assessments · :quarantined in quarantine',
            none: 'No data source is set up.',
        },
    },
    dashboard: {
        filters: {
            label: 'Filters', year: 'Year', quarter: 'Quarter', lga: 'LGA', level: 'Level', owner: 'Owner',
            all_years: 'All years', all_quarters: 'All quarters', all_lgas: 'All LGAs', all_levels: 'All levels', all_owners: 'Public and private',
            quarter_option: 'Q:quarter', quarter_needs_year: 'Choose a year to pick a quarter.', clear: 'Clear filters',
            duration: 'Duration', duration_quarters: 'Quarters', duration_months: 'Months', month_option: ':month (Q:quarter)',
            cleared: 'Filters cleared.', announce: 'Showing :count facilities. Average :average.', announce_none: 'No facilities match these filters.',
        },
    },
};

const source = (overrides: Partial<SyncStatus> = {}): SyncStatus => ({
    key: 'odk',
    name: 'ODK Central',
    state: 'healthy',
    lastSuccessAt: null,
    minutesSinceSuccess: 4,
    sinceSuccess: null,
    lastFailedAt: null,
    lastError: null,
    lastErrorClass: null,
    lastErrorHttpStatus: null,
    staleAfterMinutes: 30,
    ...overrides,
});

const shell = (...sources: SyncStatus[]): ShellStatus => ({ assessments: 2184, quarantined: 12, sources });

describe('SyncStatusBar', () => {
    it.each([
        [{ state: 'not_configured', minutesSinceSuccess: null }, 'ODK Central is not configured'],
        [{ state: 'disabled', minutesSinceSuccess: null }, 'ODK Central is switched off'],
        [{ state: 'not_connected', minutesSinceSuccess: null }, 'ODK Central: no pull has finished yet'],
        [{ state: 'healthy' }, 'ODK Central: last pull 4 min ago'],
        [{ state: 'stale', minutesSinceSuccess: 45 }, 'ODK Central: last successful pull 45 min ago, more than 30 minutes'],
        [
            { state: 'failed', lastFailedAt: '2026-10-15T08:05:00Z', lastSuccessAt: '2026-10-15T07:40:00Z', lastError: 'OdkUnreachable: ODK Central could not be reached', lastErrorClass: 'OdkUnreachable' },
            'ODK Central: last pull failed at 15 Oct 2026, 09:05 WAT (OdkUnreachable); last success 15 Oct 2026, 08:40 WAT',
        ],
        [{ state: 'failed', lastFailedAt: '2026-10-15T08:05:00Z', minutesSinceSuccess: null }, 'ODK Central: last pull failed at 15 Oct 2026, 09:05 WAT; last success never'],
        [
            { state: 'auth_failed', lastFailedAt: '2026-10-15T08:05:00Z', lastErrorClass: 'OdkAuthFailed' },
            "ODK Central refused the portal's credentials at 15 Oct 2026, 09:05 WAT; it is not pulling until they are fixed. Last success never",
        ],
    ] as const)('says the %o state in words, as a status, with the counts', async (overrides, text) => {
        const { container } = renderUi(<SyncStatusBar status={shell(source(overrides))} />, translations);

        const bar = screen.getByRole('status', { name: 'Data sources' });
        expect(bar.textContent).toContain(text);
        expect(bar.textContent).toContain('2,184 assessments · 12 in quarantine');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it("names every source in the order given (worst first), and takes the worst one's tone", () => {
        renderUi(
            <SyncStatusBar status={shell(source({ key: 'kobo', name: 'KoboToolbox', state: 'auth_failed', lastFailedAt: '2026-10-15T08:05:00Z' }), source({ state: 'healthy' }))} />,
            translations,
        );

        const bar = screen.getByRole('status');
        const text = bar.textContent ?? '';
        expect(text.indexOf('KoboToolbox refused')).toBeGreaterThanOrEqual(0);
        expect(text.indexOf('KoboToolbox refused')).toBeLessThan(text.indexOf('ODK Central: last pull 4 min ago'));
        expect(bar.className).toMatch(/text-bad/);
    });

    it.each(['not_configured', 'disabled', 'not_connected'] as const)('shows %s in the neutral style, not as a failure or a warning', (state) => {
        renderUi(<SyncStatusBar status={shell(source({ state, minutesSinceSuccess: null }), source({ key: 'kobo', name: 'KoboToolbox', state: 'not_configured' }))} />, translations);

        expect(screen.getByRole('status').className).not.toMatch(/text-(bad|warn)/);
    });

    it('says so when there is no source at all', () => {
        renderUi(<SyncStatusBar status={shell()} />, translations);

        expect(screen.getByRole('status').textContent).toContain('No data source is set up.');
    });
});

describe('FilterBar', () => {
    const options: FilterOptions = {
        rounds: [{ year: 2026, quarter: 2 }, { year: 2026, quarter: 1 }, { year: 2025, quarter: 4 }],
        lgas: [{ code: 'KDN', name: 'Kaduna North' }, { code: 'ZAR', name: 'Zaria' }],
        levels: [{ value: 'primary', label: 'Primary' }],
        owners: [{ value: 'public', label: 'Public' }, { value: 'private', label: 'Private' }],
    };
    const none: Filters = { year: null, quarter: null, month: null, lga: null, level: null, owner: null };

    beforeEach(() => {
        routerGet.mockClear();
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('labels every select and has no axe violations', async () => {
        vi.useRealTimers();
        const { container } = renderUi(<FilterBar filters={none} options={options} path="/dashboard/home" />, translations);

        const form = screen.getByRole('search', { name: 'Filters' });
        for (const label of ['Year', 'Duration', 'LGA', 'Level', 'Owner']) {
            expect(within(form).getByLabelText(label).tagName).toBe('SELECT');
        }
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('offers only the quarters that have a round in the chosen year, and needs a year first', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026 }} options={options} path="/dashboard/home" />, translations);

        const duration = screen.getByLabelText('Duration');
        expect(within(duration).getByRole('group', { name: 'Quarters' }).textContent).toBe('Q1Q2');
        expect(within(duration).getAllByRole('option')[0]?.textContent).toBe('All quarters');
    });

    it('offers every month of those quarters, under Months', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026 }} options={options} path="/dashboard/home" />, translations);

        const months = within(screen.getByLabelText('Duration')).getByRole('group', { name: 'Months' });
        expect(within(months).getAllByRole('option').map((o) => o.textContent)).toEqual(['January (Q1)', 'February (Q1)', 'March (Q1)', 'April (Q2)', 'May (Q2)', 'June (Q2)']);
    });

    it('works before a year is chosen: it offers the latest year, and choosing sets that year', () => {
        renderUi(<FilterBar filters={none} options={options} path="/dashboard/home" />, translations);

        const duration = screen.getByLabelText('Duration') as HTMLSelectElement;
        expect(duration.disabled).toBe(false);
        expect(within(duration).getByRole('group', { name: 'Quarters' }).textContent).toBe('Q1Q2');

        fireEvent.change(duration, { target: { value: 'm4' } });
        act(() => {
            vi.advanceTimersByTime(300);
        });
        expect(routerGet).toHaveBeenLastCalledWith('/dashboard/home', { year: '2026', month: '4' }, expect.anything());
        expect((screen.getByLabelText('Year') as HTMLSelectElement).value).toBe('2026');
    });

    it('sends a month as the month alone, and a quarter as the quarter alone', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026, quarter: 2 }} options={options} path="/dashboard/home" />, translations);

        fireEvent.change(screen.getByLabelText('Duration'), { target: { value: 'm5' } });
        act(() => {
            vi.advanceTimersByTime(300);
        });
        expect(routerGet).toHaveBeenLastCalledWith('/dashboard/home', { year: '2026', month: '5' }, expect.anything());

        fireEvent.change(screen.getByLabelText('Duration'), { target: { value: 'q1' } });
        act(() => {
            vi.advanceTimersByTime(300);
        });
        expect(routerGet).toHaveBeenLastCalledWith('/dashboard/home', { year: '2026', quarter: '1' }, expect.anything());
    });

    it('shows a month from the server as the month chosen, not its quarter', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026, quarter: 2, month: 6 }} options={options} path="/dashboard/home" />, translations);

        expect((screen.getByLabelText('Duration') as HTMLSelectElement).value).toBe('m6');
    });

    it('waits 300 ms, then reloads in place with the new query, keeping state, scroll and history', () => {
        renderUi(<FilterBar filters={none} options={options} path="/dashboard/lga" />, translations);

        fireEvent.change(screen.getByLabelText('Year'), { target: { value: '2026' } });
        fireEvent.change(screen.getByLabelText('LGA'), { target: { value: 'ZAR' } });
        act(() => {
            vi.advanceTimersByTime(299);
        });
        expect(routerGet).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(1);
        });
        expect(routerGet).toHaveBeenCalledTimes(1);
        expect(routerGet).toHaveBeenCalledWith('/dashboard/lga', { year: '2026', lga: 'ZAR' }, expect.objectContaining({ preserveState: true, preserveScroll: true, replace: true }));
    });

    it('drops a month the new year has no round for', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026, quarter: 2, month: 5 }} options={options} path="/dashboard/home" />, translations);

        fireEvent.change(screen.getByLabelText('Year'), { target: { value: '2025' } });
        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(routerGet).toHaveBeenCalledWith('/dashboard/home', { year: '2025' }, expect.anything());
    });

    it('drops a quarter the new year has no round for', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026, quarter: 2 }} options={options} path="/dashboard/home" />, translations);

        fireEvent.change(screen.getByLabelText('Year'), { target: { value: '2025' } });
        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(routerGet).toHaveBeenCalledWith('/dashboard/home', { year: '2025' }, expect.anything());
    });

    it('has no Clear filters link when nothing is set', () => {
        renderUi(<FilterBar filters={none} options={options} path="/dashboard/home" />, translations);

        expect(screen.queryByRole('link', { name: 'Clear filters' })).toBeNull();
    });

    it('offers Clear filters, to the bare page, when a filter is set', () => {
        renderUi(<FilterBar filters={{ ...none, owner: 'private' }} options={options} path="/dashboard/home" />, translations);

        expect(screen.getByRole('link', { name: 'Clear filters' }).getAttribute('href')).toBe('/dashboard/home');
    });
});
