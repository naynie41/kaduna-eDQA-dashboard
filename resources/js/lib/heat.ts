import type { CSSProperties } from 'react';

/**
 * Heat shading for month cells (CONVENTION.md §6), the prototype's formula:
 * t = clamp((v − 55) / 45, 0, 1) → hsl(146, 28 + 26t %, 96 − 30t %).
 *
 * The one place the client reads a score's value, and only to pick a colour: the cell always
 * shows the server's ScoreValue.display. Text stays ink on every shade (the prototype's white
 * text on dark cells falls to about 1.5:1).
 */
export type HeatTone = { saturation: number; lightness: number };

export function heatTone(value: number): HeatTone {
    const t = Math.min(1, Math.max(0, (value - 55) / 45));

    return { saturation: Math.round(28 + t * 26), lightness: Math.round(96 - t * 30) };
}

export function heatStyle(value: number | null): CSSProperties {
    if (value === null) {
        return {};
    }

    const { saturation, lightness } = heatTone(value);

    return { backgroundColor: `hsl(146 ${saturation}% ${lightness}%)`, color: 'var(--color-ink)' };
}
