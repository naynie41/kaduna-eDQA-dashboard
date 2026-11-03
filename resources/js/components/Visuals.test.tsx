import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import MonthFigure from '@/components/MonthFigure';
import OwnerSplit from '@/components/OwnerSplit';
import ScoreGroupFigure from '@/components/ScoreGroupFigure';
import SegmentedControl from '@/components/SegmentedControl';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { MonthSlotPoint } from '@/types/dashboard';
import type { ScoreValue } from '@/types/score';

const translations = {
    ...testTranslations,
    enums: { dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    dashboard: {
        visuals: {
            slot_title: 'Month :slot of the quarter',
            chart: ':title: grouped bars',
            legend: 'Series',
            overall: 'Overall',
            quarter: 'Quarter',
            no_scores: 'No scores for this month in view.',
        },
        home: { public: 'Public facilities', private: 'Private facilities', visited: ':count visited', overall_caption: 'overall quality score' },
    },
};

const score = (display: string): ScoreValue => ({ value: Number(display), display, headline: display, band: 'acceptable', bandLabel: 'Acceptable' });

const point = (roundId: number, quarter: number, label: string, chartLabel: string): MonthSlotPoint => ({
    roundId,
    year: 2026,
    quarter,
    slot: 1,
    month: (quarter - 1) * 3 + 1,
    label,
    chartLabel,
    availability: score('76.4'),
    consistency: score('76.7'),
    validity: score('84.3'),
    overall: score('79.1'),
});

describe('MonthFigure', () => {
    it('names the month when one quarter is in view, captions the takeaway and lists the series in order', async () => {
        const { container } = renderUi(<MonthFigure slot={1} points={[point(2, 2, 'April 2026', 'April (Q2 2026)')]} takeaway="April 2026: overall 79.1." />, translations);

        const figure = screen.getByRole('figure', { name: 'April 2026' });
        expect(within(figure).getByText('April 2026: overall 79.1.')).toBeTruthy();
        expect(within(screen.getByRole('list', { name: 'Series' })).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Availability', 'Consistency', 'Validity', 'Overall']);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('calls it by slot across quarters, and switches to the table by keyboard', async () => {
        renderUi(<MonthFigure slot={1} points={[point(1, 1, 'January 2026', 'January (Q1 2026)'), point(2, 2, 'April 2026', 'April (Q2 2026)')]} takeaway={null} />, translations);

        expect(screen.getByRole('figure', { name: 'Month 1 of the quarter' })).toBeTruthy();
        expect(screen.getByText('No scores for this month in view.')).toBeTruthy();

        screen.getByRole('button', { name: 'Show as table' }).focus();
        await userEvent.keyboard('{Enter}');

        const table = screen.getByRole('table', { name: 'Month 1 of the quarter' });
        expect(within(table).getAllByRole('rowheader').map((th) => th.textContent)).toEqual(['January (Q1 2026)', 'April (Q2 2026)']);
        expect(within(table).getAllByRole('columnheader').map((th) => th.textContent)).toEqual(['Quarter', 'Availability', 'Consistency', 'Validity', 'Overall']);
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Show as chart' }));
    });
});

describe('SegmentedControl, disabled', () => {
    it('stays reachable, says why, and ignores presses', async () => {
        const onChange = vi.fn();
        renderUi(
            <>
                <SegmentedControl
                    label="Facilities shown"
                    value="public"
                    onChange={onChange}
                    disabled
                    describedBy="why"
                    options={[
                        { value: 'all', label: 'Overall' },
                        { value: 'public', label: 'Public' },
                    ]}
                />
                <p id="why">The owner filter is set to Public.</p>
            </>,
        );

        const group = screen.getByRole('group', { name: 'Facilities shown' });
        expect(group.getAttribute('aria-describedby')).toBe('why');
        const overall = screen.getByRole('button', { name: 'Overall' });
        expect(overall.getAttribute('aria-disabled')).toBe('true');
        await userEvent.tab();
        expect(document.activeElement).toBe(overall);
        await userEvent.keyboard('{Enter}');
        expect(onChange).not.toHaveBeenCalled();
    });
});

describe('ScoreGroupFigure (D-75)', () => {
    const row = (key: number, label: string, overall: string) => ({ key, label, availability: score('75.0'), consistency: score('80.0'), validity: score('81.0'), overall: score(overall) });

    it('shows each month of the quarter with its three dimensions and overall, as a table too', async () => {
        const { container } = renderUi(
            <ScoreGroupFigure title="Q2 2026, month by month" description="Each month of the quarter." rowHeader="Month" rows={[row(4, 'April 2026', '79.1'), row(5, 'May 2026', '78.7'), row(6, 'June 2026', '78.2')]} />,
            translations,
        );

        expect(screen.getByRole('figure', { name: 'Q2 2026, month by month' })).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Show as table' }));

        const table = screen.getByRole('table', { name: 'Q2 2026, month by month' });
        expect(within(table).getAllByRole('columnheader').map((th) => th.textContent)).toEqual(['Month', 'Availability', 'Consistency', 'Validity', 'Overall']);
        expect(within(table).getAllByRole('rowheader').map((th) => th.textContent)).toEqual(['April 2026', 'May 2026', 'June 2026']);
        expect(within(table).getAllByRole('row')[3]?.textContent).toContain('78.2');
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('OwnerSplit with all facilities (Monitor, D-75)', () => {
    const group = (facilities: number, overall: string) => ({ facilities, availability: score('75.0'), consistency: score('80.0'), validity: score('81.0'), overall: score(overall) });

    it('puts all facilities first, beside public and private, each with a heading', async () => {
        const { container } = renderUi(<OwnerSplit split={{ public: group(5, '78.6'), private: group(2, '79.0') }} all={{ title: 'All facilities', scores: group(7, '78.7') }} />, translations);

        expect(screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual(['All facilities', 'Public facilities', 'Private facilities']);
        expect(screen.getByText('7 visited')).toBeTruthy();
        expect(screen.getByText('78.7')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('stays two sides on Home, with no all-facilities side', () => {
        renderUi(<OwnerSplit split={{ public: group(5, '78.6'), private: group(2, '79.0') }} />, translations);

        expect(screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual(['Public facilities', 'Private facilities']);
    });
});
