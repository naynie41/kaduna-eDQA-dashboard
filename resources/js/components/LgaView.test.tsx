import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import LgaGrid from '@/components/LgaGrid';
import LgaTable from '@/components/LgaTable';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { LgaRow } from '@/types/dashboard';
import type { ScoreValue } from '@/types/score';

const translations = {
    ...testTranslations,
    enums: { dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    dashboard: {
        lga: {
            grid_label: 'Filter by local government',
            tile_name: ':lga, :score, :band, :count facilities',
            tile_facilities: ':count facilities',
            lga: 'LGA', facilities: 'Facilities', overall: 'Overall', status: 'Status', of_quarter: 'of the quarter',
            sorted: 'Sorted by :column, :direction.', ascending: 'lowest first', descending: 'highest first',
            nothing: 'No local government has assessments in this selection.',
        },
    },
};

const score = (display: string, band: ScoreValue['band'], bandLabel: string): ScoreValue => ({ value: Number(display), display, headline: display, band, bandLabel });
const none: ScoreValue = { value: null, display: '—', headline: '—', band: null, bandLabel: 'No data' };

const row = (code: string, name: string, overall: ScoreValue, facilities: number): LgaRow => ({
    lgaId: code.length, code, name, facilities,
    availability: overall, consistency: overall, validity: overall, m1: overall, m2: overall, m3: overall, overall,
});

const lgas = [
    row('ZAR', 'Zaria', score('83.2', 'acceptable', 'Acceptable'), 25),
    row('CHI', 'Chikun', score('84.2', 'acceptable', 'Acceptable'), 91),
    row('BGW', 'Birnin Gwari', score('77.4', 'review', 'Review'), 11),
    row('KUD', 'Kudan', none, 0),
];

describe('LgaGrid', () => {
    it('names each tile in full, highest first, no data last, and presses the chosen LGA', async () => {
        const { container } = renderUi(<LgaGrid lgas={lgas} selected="CHI" onToggle={() => undefined} />, translations);

        const group = screen.getByRole('group', { name: 'Filter by local government' });
        expect(within(group).getAllByRole('button').map((b) => b.getAttribute('aria-label'))).toEqual([
            'Chikun, 84.2, Acceptable, 91 facilities',
            'Zaria, 83.2, Acceptable, 25 facilities',
            'Birnin Gwari, 77.4, Review, 11 facilities',
            'Kudan, —, No data, 0 facilities',
        ]);
        expect(within(group).getByRole('button', { name: /^Chikun/, pressed: true })).toBeTruthy();
        expect(within(group).getByRole('button', { name: /^Zaria/, pressed: false })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('toggles from the keyboard', async () => {
        const onToggle = vi.fn();
        renderUi(<LgaGrid lgas={lgas} selected={null} onToggle={onToggle} />, translations);

        screen.getByRole('button', { name: /^Zaria/ }).focus();
        await userEvent.keyboard('{Enter}');

        expect(onToggle).toHaveBeenCalledWith('ZAR');
    });
});

describe('LgaTable', () => {
    const rounds = [{ year: 2026, quarter: 2 }];

    it('has row headers, real month names for one quarter, a status in words, and sorts highest first', async () => {
        const { container } = renderUi(<LgaTable lgas={lgas} rounds={rounds} caption="All four reports by local government: Q2 2026." />, translations);

        const table = screen.getByRole('table', { name: 'All four reports by local government: Q2 2026.' });
        expect(within(table).getAllByRole('columnheader').map((h) => h.textContent?.replace(/[↑↓↕]/g, ''))).toEqual([
            'LGA', 'Facilities', 'Availability', 'Consistency', 'Validity', 'April', 'May', 'June', 'Overall', 'Status',
        ]);
        expect(within(table).getAllByRole('rowheader').map((h) => h.textContent)).toEqual(['Chikun', 'Zaria', 'Birnin Gwari', 'Kudan']);
        expect(within(table).getByRole('columnheader', { name: /Overall/ }).getAttribute('aria-sort')).toBe('descending');
        expect(within(table).getAllByText('Review').length).toBeGreaterThan(0);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says Month 1/2/3 "of the quarter" when several quarters are in view', () => {
        renderUi(<LgaTable lgas={lgas} rounds={[{ year: 2026, quarter: 1 }, { year: 2026, quarter: 2 }]} caption="All rounds" />, translations);

        expect(screen.getByRole('columnheader', { name: 'Month 1 of the quarter' })).toBeTruthy();
    });

    it('sorts by another column on request, with aria-sort following', async () => {
        renderUi(<LgaTable lgas={lgas} rounds={rounds} caption="LGAs" />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'LGA' }));
        expect(screen.getAllByRole('rowheader').map((h) => h.textContent)).toEqual(['Birnin Gwari', 'Chikun', 'Kudan', 'Zaria']);
        expect(screen.getByRole('columnheader', { name: /^LGA/ }).getAttribute('aria-sort')).toBe('ascending');

        await userEvent.click(screen.getByRole('button', { name: 'LGA' }));
        expect(screen.getAllByRole('rowheader').map((h) => h.textContent)).toEqual(['Zaria', 'Kudan', 'Chikun', 'Birnin Gwari']);
    });
});
