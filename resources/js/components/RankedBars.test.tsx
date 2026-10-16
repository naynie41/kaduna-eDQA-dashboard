import { screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import RankedBars from '@/components/RankedBars';
import ScoreTag from '@/components/ScoreTag';
import { axeCheck, renderUi } from '@/test/render';
import type { ScoreValue } from '@/types/score';

const items = [
    { key: 1, label: 'Zaria', value: 12, valueText: '12 facilities visited' },
    { key: 2, label: 'Kauru', value: 3, valueText: '3 facilities visited' },
];

const score = (value: number, display: string, band: ScoreValue['band'], bandLabel: string): ScoreValue => ({ value, display, headline: display, band, bandLabel });

describe('RankedBars', () => {
    it('is a named ordered list: each name and count is text, the bars are hidden from assistive technology', async () => {
        const { container } = renderUi(<RankedBars label="Facilities visited, by local government" items={items} empty="None" />);

        const list = screen.getByRole('list', { name: 'Facilities visited, by local government' });
        expect(list.tagName).toBe('OL');
        expect(within(list).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Zaria12 facilities visited12', 'Kauru3 facilities visited3']);
        // The bar and the repeated number are for the eye only.
        expect(container.querySelectorAll('li svg[aria-hidden="true"]')).toHaveLength(2);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('scales bars by viewBox, the longest row filling it by default', () => {
        const { container } = renderUi(<RankedBars label="Coverage" items={items} empty="None" />);

        const svg = container.querySelector('li svg') as SVGElement;
        expect(svg.getAttribute('viewBox')).toBe('0 0 100 10');
        expect([...container.querySelectorAll('li svg rect:nth-child(2)')].map((rect) => rect.getAttribute('width'))).toEqual(['100', '25']);
    });

    it('explains the marker in words, and draws it on every bar', () => {
        const { container } = renderUi(<RankedBars label="Coverage" items={items} marker={{ value: 7.5, label: 'State mean: 7.5 facilities visited per local government' }} empty="None" />);

        expect(screen.getByText('State mean: 7.5 facilities visited per local government')).toBeTruthy();
        expect([...container.querySelectorAll('li line')].map((line) => line.getAttribute('x1'))).toEqual(['62.5', '62.5']); // 7.5 of the longest, 12
    });

    it('draws scores on a 0–100 scale in their band colour, the value and band printed as text', () => {
        const scores = [
            { key: 'a', label: 'Alpha', value: 92.4, tone: 'strong' as const, detail: <ScoreTag score={score(92.4, '92.4', 'strong', 'Strong')} /> },
            { key: 'b', label: 'Bravo', value: 64, tone: 'needs_action' as const, detail: <ScoreTag score={score(64, '64.0', 'needs_action', 'Needs action')} /> },
        ];
        const { container } = renderUi(<RankedBars label="Overall, by local government" items={scores} max={100} empty="None" />);

        const bars = [...container.querySelectorAll('li svg rect:nth-child(2)')];
        expect(bars.map((rect) => [rect.getAttribute('width'), rect.getAttribute('class')])).toEqual([
            ['92.4', 'fill-ok'],
            ['64', 'fill-bad'],
        ]);
        expect(screen.getAllByRole('listitem').map((li) => li.textContent)).toEqual(['Alpha92.4Strong', 'Bravo64.0Needs action']);
    });

    it('says when there is nothing to rank', () => {
        renderUi(<RankedBars label="Coverage" items={[]} empty="No facilities visited in this selection." />);

        expect(screen.getByText('No facilities visited in this selection.')).toBeTruthy();
        expect(screen.queryByRole('list')).toBeNull();
    });
});
