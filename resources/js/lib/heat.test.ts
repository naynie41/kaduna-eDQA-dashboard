import { describe, expect, it } from 'vitest';
import { heatStyle, heatTone } from '@/lib/heat';

// CONVENTION.md §6: t = clamp((v − 55) / 45, 0, 1) → hsl(146, 28+26t %, 96−30t %), text always ink.

describe('heatTone', () => {
    it('follows the prototype formula', () => {
        expect(heatTone(55)).toEqual({ saturation: 28, lightness: 96 });
        expect(heatTone(100)).toEqual({ saturation: 54, lightness: 66 });
        expect(heatTone(77.5)).toEqual({ saturation: 41, lightness: 81 });
    });

    it('clamps below 55 and above 100', () => {
        expect(heatTone(12)).toEqual(heatTone(55));
        expect(heatTone(140)).toEqual(heatTone(100));
    });
});

describe('heatStyle', () => {
    it('shades the background and always keeps ink text, even on the darkest cell', () => {
        expect(heatStyle(100)).toEqual({ backgroundColor: 'hsl(146 54% 66%)', color: 'var(--color-ink)' });
        expect(heatStyle(60).color).toBe('var(--color-ink)');
    });

    it('leaves a cell with no score unshaded', () => {
        expect(heatStyle(null)).toEqual({});
    });
});
