import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { LgaRow } from '@/types/dashboard';
import type { Band } from '@/types/score';

export type LgaTileProps = {
    lga: LgaRow;
    pressed: boolean;
    onToggle: (code: string) => void;
};

// The band as a left border and the value's colour: supplementary only, since the band is in the
// tile's accessible name and the value is written on it.
const TONE: Record<Band, { border: string; text: string }> = {
    strong: { border: 'border-l-ok', text: 'text-ok' },
    acceptable: { border: 'border-l-ok', text: 'text-ok' },
    review: { border: 'border-l-warn-fill', text: 'text-warn' },
    needs_action: { border: 'border-l-bad', text: 'text-bad' },
};

/**
 * One LGA in the grid (ARCHITECTURE.md §9.2.3): a toggle button for the LGA filter, named in full
 * ("Chikun, 84.2, Acceptable, 91 facilities") and pressed while that LGA is the filter. At least
 * 44px tall for touch (WCAG 2.5.8).
 */
export default function LgaTile({ lga, pressed, onToggle }: LgaTileProps) {
    const t = useTranslate();
    const tone = lga.overall.band === null ? { border: 'border-l-transparent', text: 'text-ink-2' } : TONE[lga.overall.band];
    const facilities = formatCount(lga.facilities);

    return (
        <button
            type="button"
            aria-pressed={pressed}
            aria-label={t('dashboard.lga.tile_name', { lga: lga.name, score: lga.overall.display, band: lga.overall.bandLabel, count: facilities })}
            onClick={() => onToggle(lga.code)}
            className={`block min-h-11 w-full border-0 border-l-[3px] px-2.75 pt-2.5 pb-2.75 text-left ${tone.border} ${pressed ? 'bg-wash forced-colors:forced-color-adjust-none forced-colors:bg-[Highlight] forced-colors:text-[HighlightText]' : 'bg-panel hover:bg-paper'}`}
        >
            <span className="block text-[12.5px] leading-tight">{lga.name}</span>
            <span className={`mt-1 block font-display text-[17px] font-semibold tabular-nums ${tone.text}`}>{lga.overall.display}</span>
            <span className="block text-[11px] text-ink-2">{t('dashboard.lga.tile_facilities', { count: facilities })}</span>
        </button>
    );
}
