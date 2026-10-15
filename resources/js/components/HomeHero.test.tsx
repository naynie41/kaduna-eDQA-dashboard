import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import HomeHero from '@/components/HomeHero';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { DimensionScores, StateSummary } from '@/types/dashboard';
import type { ScoreValue } from '@/types/score';

const translations = {
    ...testTranslations,
    enums: { dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    dashboard: {
        home: {
            average_label: 'Average quality score',
            averaged_across: 'Averaged across :count facilities and the three months of each assessed quarter.',
            no_assessments: 'No assessments match these filters.',
            facilities: 'Facilities visited',
            lgas: 'Local governments',
            strong: 'Facilities scoring :threshold+',
            weak: 'Facilities under :threshold',
            dimensions_title: 'The three dimensions',
            dimensions_intro: 'Each facility is scored out of 100.',
            dimension_help: { availability: 'Was it there?', consistency: 'Does it agree?', validity: 'Is it valid?', overall: 'The mean of the three' },
            overall: 'Overall',
            marker_legend: 'The mark on each bar is the Strong threshold of :threshold.',
            weakest_note: ':dimension is the weakest dimension, at :score.',
        },
    },
};

const value = (display: string, band: ScoreValue['band'], bandLabel: string): ScoreValue => ({ value: Number(display), display, headline: display.split('.')[0] ?? display, band, bandLabel });
const none: ScoreValue = { value: null, display: '—', headline: '—', band: null, bandLabel: 'No data' };

const hero = (overrides: Partial<StateSummary> = {}): StateSummary => ({
    overall: value('86.0', 'acceptable', 'Acceptable'),
    facilitiesVisited: 439,
    lgasCovered: 23,
    facilitiesStrong: 175,
    facilitiesWeak: 21,
    strongAt: 90,
    weakBelow: 70,
    ...overrides,
});

const dimensions: DimensionScores = {
    availability: value('89.9', 'acceptable', 'Acceptable'),
    consistency: value('77.5', 'review', 'Review'),
    validity: value('90.5', 'strong', 'Strong'),
    overall: value('86.0', 'acceptable', 'Acceptable'),
    weakest: 'consistency',
};

describe('HomeHero', () => {
    it('reads as description lists: the average, then the four counts with thresholds from the rule version', async () => {
        const { container } = renderUi(<HomeHero hero={hero({ strongAt: 85, weakBelow: 65 })} dimensions={dimensions} />, translations);

        const terms = screen.getAllByRole('term');
        const average = terms.find((term) => term.textContent === 'Average quality score');
        expect(average?.parentElement?.querySelector('dd')?.textContent).toContain('86');
        expect(screen.getByText('Averaged across 439 facilities and the three months of each assessed quarter.')).toBeTruthy();

        expect(terms.slice(1).map((term) => term.textContent)).toEqual([
            'Facilities visited', 'Local governments', 'Facilities scoring 85+', 'Facilities under 65',
        ]);
        expect(screen.getAllByRole('definition').slice(1).map((d) => d.textContent)).toEqual(['439', '23', '175', '21']);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('lists the three dimensions and the overall with their bands in words, the mark explained, and the weakest named', () => {
        renderUi(<HomeHero hero={hero()} dimensions={dimensions} />, translations);

        const bars = screen.getByRole('region', { name: 'The three dimensions' });
        const items = within(bars).getAllByRole('listitem').map((item) => item.textContent);
        expect(items).toHaveLength(4);
        expect(items[1]).toContain('Consistency');
        expect(items[1]).toContain('77.5');
        expect(items[1]).toContain('Review');
        expect(within(bars).getByText('The mark on each bar is the Strong threshold of 90.')).toBeTruthy();
        expect(within(bars).getByText('Consistency is the weakest dimension, at 77.5.')).toBeTruthy();
    });

    it('renders with no data at all: dashes and "No data", no weakest note, nothing broken', async () => {
        const { container } = renderUi(
            <HomeHero
                hero={hero({ overall: none, facilitiesVisited: 0, lgasCovered: 0, facilitiesStrong: 0, facilitiesWeak: 0, strongAt: null, weakBelow: null })}
                dimensions={{ availability: none, consistency: none, validity: none, overall: none, weakest: null }}
            />,
            translations,
        );

        expect(screen.getAllByRole('definition')[0]?.textContent).toContain('—');
        expect(screen.getByText('No assessments match these filters.')).toBeTruthy();
        expect(screen.getAllByText('No data')).toHaveLength(5); // the average (for screen readers) and the four bars
        expect(screen.getByText('Facilities scoring —+')).toBeTruthy();
        expect(screen.queryByText(/weakest dimension/)).toBeNull();
        expect(screen.queryByText(/The mark on each bar/)).toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});
