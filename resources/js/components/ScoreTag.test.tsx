import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import ScoreTag from './ScoreTag';
import type { ScoreValue } from '@/types/score';

// Status is never carried by colour alone (CLAUDE.md hard rule 11): the tag always renders the
// value and the band label as text. Rendered to static markup, so no DOM library is needed.

const score = (overrides: Partial<ScoreValue> = {}): ScoreValue => ({
    value: 92.25,
    display: '92.3',
    headline: '92',
    band: 'strong',
    bandLabel: 'Strong',
    ...overrides,
});

const textOf = (html: string): string => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

describe('ScoreTag', () => {
    it('renders the value and the band label as text', () => {
        expect(textOf(renderToStaticMarkup(<ScoreTag score={score()} />))).toBe('92.3 Strong');
    });

    it('labels every band in words, not only by colour', () => {
        const bands = [
            ['strong', 'Strong'],
            ['acceptable', 'Acceptable'],
            ['review', 'Review'],
            ['needs_action', 'Needs action'],
        ] as const;

        for (const [band, label] of bands) {
            const html = renderToStaticMarkup(<ScoreTag score={score({ band, bandLabel: label })} />);
            expect(textOf(html)).toContain(label);
        }
    });

    it('gives Strong and Acceptable the same colour, so only the label tells them apart', () => {
        const strong = renderToStaticMarkup(<ScoreTag score={score()} />);
        const acceptable = renderToStaticMarkup(<ScoreTag score={score({ band: 'acceptable', bandLabel: 'Acceptable', display: '85.0' })} />);

        expect(strong).toContain('text-ok');
        expect(acceptable).toContain('text-ok');
        expect(textOf(strong)).not.toBe(textOf(acceptable));
    });

    it('uses the warn and bad tokens for Review and Needs action', () => {
        expect(renderToStaticMarkup(<ScoreTag score={score({ band: 'review', bandLabel: 'Review' })} />)).toContain('text-warn');
        expect(renderToStaticMarkup(<ScoreTag score={score({ band: 'needs_action', bandLabel: 'Needs action' })} />)).toContain('text-bad');
    });

    it('shows the no-data dash with its label', () => {
        const html = renderToStaticMarkup(<ScoreTag score={score({ value: null, display: '—', headline: '—', band: null, bandLabel: 'No data' })} />);

        expect(textOf(html)).toBe('— No data');
        expect(html).not.toContain('text-ok');
    });

    it('renders exactly what the server sent: no rounding in the component', () => {
        expect(textOf(renderToStaticMarkup(<ScoreTag score={score({ value: 89.96, display: '90.0' })} />))).toBe('90.0 Strong');
    });
});
