import StateMark from '@/components/StateMark';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { LgaRow } from '@/types/dashboard';
import type { Band } from '@/types/score';

export type LgaTileProps = {
    lga: LgaRow;
    pressed: boolean;
    onToggle: (code: string) => void;
};

// The band as the value's colour, a shape and a meter: supplementary only, since the band is in
// the tile's accessible name and the value is written on it.
const TONE: Record<Band, { text: string; fill: string; mark: string }> = {
    strong: { text: 'text-ok', fill: 'bg-ok', mark: '●' },
    acceptable: { text: 'text-ok', fill: 'bg-ok', mark: '◐' },
    review: { text: 'text-warn', fill: 'bg-warn-fill', mark: '▲' },
    needs_action: { text: 'text-bad', fill: 'bg-bad', mark: '■' },
};

/**
 * One LGA in the grid (ARCHITECTURE.md §9.2.3): a toggle button for the LGA filter, named in full
 * ("Chikun, 84.2, Acceptable, 91 facilities") and pressed while that LGA is the filter. At least
 * 44px tall for touch (WCAG 2.5.8). The meter along the foot is the score on 0–100.
 */
export default function LgaTile({ lga, pressed, onToggle }: LgaTileProps) {
    const t = useTranslate();
    const tone = lga.overall.band === null ? { text: 'text-ink-2', fill: 'bg-transparent', mark: '–' } : TONE[lga.overall.band];
    const facilities = formatCount(lga.facilities);
    const width = lga.overall.value === null ? 0 : Math.min(100, Math.max(0, lga.overall.value));

    return (
        <button
            type="button"
            aria-pressed={pressed}
            aria-label={t('dashboard.lga.tile_name', { lga: lga.name, score: lga.overall.display, band: lga.overall.bandLabel, count: facilities })}
            onClick={() => onToggle(lga.code)}
            className={`group relative block min-h-11 w-full px-3.5 pt-3 pb-4 text-left transition-colors duration-150 ${
                pressed ? 'bg-wash ring-2 ring-deep ring-inset forced-colors:forced-color-adjust-none forced-colors:bg-[Highlight] forced-colors:text-[HighlightText]' : 'bg-panel hover:bg-paper'
            }`}
        >
            <span className="flex items-center justify-between gap-2 text-xs leading-tight font-medium text-ink">
                <span className="truncate">{lga.name}</span>
                <StateMark mark={tone.mark} className={`size-2 ${tone.text}`} />
            </span>
            <span className={`mt-1.5 block font-display text-xl font-semibold tracking-[-0.02em] tabular-nums ${tone.text}`}>{lga.overall.display}</span>
            <span className="mt-0.5 block text-2xs text-ink-2">{t('dashboard.lga.tile_facilities', { count: facilities })}</span>
            <span aria-hidden="true" className="absolute inset-x-3.5 bottom-2 h-[3px] overflow-hidden rounded-full bg-paper">
                <span className={`block h-full rounded-full ${tone.fill}`} style={{ width: `${width}%` }} />
            </span>
        </button>
    );
}
