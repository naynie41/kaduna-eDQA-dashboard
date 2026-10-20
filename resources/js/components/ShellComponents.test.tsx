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
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        sync: {
            not_configured: 'ODK Central is not configured, so no submissions are being pulled.',
            not_connected: 'ODK Central is configured; no pull has finished yet.',
            ok: 'Last pull :minutes min ago · :assessments assessments · :quarantined in quarantine',
            stale: 'Last successful pull :minutes min ago, more than :limit minutes · :assessments assessments · :quarantined in quarantine',
            failed: 'Last pull failed at :time (:error); last success :success · :assessments assessments · :quarantined in quarantine',
            failed_no_error: 'Last pull failed at :time; last success :success · :assessments assessments · :quarantined in quarantine',
            never_succeeded: 'never',
        },
    },
    dashboard: {
        filters: {
            label: 'Filters', year: 'Year', quarter: 'Quarter', lga: 'LGA', level: 'Level', owner: 'Owner',
            all_years: 'All years', all_quarters: 'All quarters', all_lgas: 'All LGAs', all_levels: 'All levels', all_owners: 'Public and private',
            quarter_option: 'Q:quarter', quarter_needs_year: 'Choose a year to pick a quarter.', clear: 'Clear filters',
            cleared: 'Filters cleared.', announce: 'Showing :count facilities. Average :average.', announce_none: 'No facilities match these filters.',
        },
    },
};

const sync = (overrides: Partial<SyncStatus>): ShellStatus => ({
    assessments: 2184,
    quarantined: 12,
    sync: { state: 'ok', lastSuccessAt: null, minutesSinceSuccess: 4, lastFailedAt: null, lastError: null, lastErrorClass: null, staleAfterMinutes: 30, ...overrides },
});

describe('SyncStatusBar', () => {
    it.each([
        [{ state: 'not_configured', minutesSinceSuccess: null }, 'ODK Central is not configured, so no submissions are being pulled.'],
        [{ state: 'not_connected', minutesSinceSuccess: null }, 'ODK Central is configured; no pull has finished yet.'],
        [{ state: 'ok' }, 'Last pull 4 min ago · 2,184 assessments · 12 in quarantine'],
        [{ state: 'stale', minutesSinceSuccess: 45 }, 'Last successful pull 45 min ago, more than 30 minutes · 2,184 assessments · 12 in quarantine'],
        [
            { state: 'failed', lastFailedAt: '2026-10-15T08:05:00Z', lastSuccessAt: '2026-10-15T07:40:00Z', lastError: 'OdkUnreachable: ODK Central could not be reached', lastErrorClass: 'OdkUnreachable' },
            'Last pull failed at 15 Oct 2026, 09:05 WAT (OdkUnreachable); last success 15 Oct 2026, 08:40 WAT · 2,184 assessments · 12 in quarantine',
        ],
        [{ state: 'failed', lastFailedAt: '2026-10-15T08:05:00Z', minutesSinceSuccess: null }, 'Last pull failed at 15 Oct 2026, 09:05 WAT; last success never · 2,184 assessments · 12 in quarantine'],
    ] as const)('says the %o state in words, as a status', async (overrides, text) => {
        const { container } = renderUi(<SyncStatusBar status={sync(overrides)} />, translations);

        expect(screen.getByRole('status').textContent).toContain(text);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it.each(['not_configured', 'not_connected'] as const)('shows %s in the neutral style, not as a failure or a warning', (state) => {
        renderUi(<SyncStatusBar status={sync({ state, minutesSinceSuccess: null })} />, translations);

        expect(screen.getByRole('status').className).not.toMatch(/text-(bad|warn)/);
    });
});

describe('FilterBar', () => {
    const options: FilterOptions = {
        rounds: [{ year: 2026, quarter: 2 }, { year: 2026, quarter: 1 }, { year: 2025, quarter: 4 }],
        lgas: [{ code: 'KDN', name: 'Kaduna North' }, { code: 'ZAR', name: 'Zaria' }],
        levels: [{ value: 'primary', label: 'Primary' }],
        owners: [{ value: 'public', label: 'Public' }, { value: 'private', label: 'Private' }],
    };
    const none: Filters = { year: null, quarter: null, lga: null, level: null, owner: null };

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
        for (const label of ['Year', 'Quarter', 'LGA', 'Level', 'Owner']) {
            expect(within(form).getByLabelText(label).tagName).toBe('SELECT');
        }
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('offers only the quarters that have a round in the chosen year, and needs a year first', () => {
        renderUi(<FilterBar filters={{ ...none, year: 2026 }} options={options} path="/dashboard/home" />, translations);

        expect(within(screen.getByLabelText('Quarter')).getAllByRole('option').map((o) => o.textContent)).toEqual(['All quarters', 'Q1', 'Q2']);
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
