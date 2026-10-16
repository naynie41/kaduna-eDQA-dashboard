import LgaTile from '@/components/LgaTile';
import { useTranslate } from '@/lib/i18n';
import { sortRows } from '@/lib/sortRows';
import type { LgaRow } from '@/types/dashboard';

export type LgaGridProps = {
    lgas: LgaRow[];
    /** The LGA filter's code, or null. */
    selected: string | null;
    onToggle: (code: string) => void;
};

/**
 * The LGA tiles (at most 23, from the lgas table), highest overall first as in the prototype, no
 * data last. A group of toggle buttons: each one sets or clears the LGA filter.
 */
export default function LgaGrid({ lgas, selected, onToggle }: LgaGridProps) {
    const t = useTranslate();
    const ordered = sortRows(lgas, (lga) => lga.overall.value, 'desc');

    return (
        <div role="group" aria-label={t('dashboard.lga.grid_label')}>
            <ul className="grid grid-cols-[repeat(auto-fill,minmax(132px,1fr))] gap-px overflow-hidden rounded-panel border border-rule bg-rule">
                {ordered.map((lga) => (
                    <li key={lga.code} className="flex">
                        <LgaTile lga={lga} pressed={lga.code === selected} onToggle={onToggle} />
                    </li>
                ))}
            </ul>
        </div>
    );
}
