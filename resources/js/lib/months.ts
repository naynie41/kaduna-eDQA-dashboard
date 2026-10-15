import type { Translate } from '@/lib/i18n';

/**
 * Month slots (ARCHITECTURE.md §3), mirroring App\Domain\Round\MonthSlot: slot 1–3 of quarter
 * 1–4 is month (quarter − 1) × 3 + slot, so Q2 slot 1 is April. Both sides are tested against
 * the same table.
 */
export function monthOfSlot(quarter: number, slot: number): number {
    if (!Number.isInteger(quarter) || quarter < 1 || quarter > 4) {
        throw new RangeError(`A quarter is 1 to 4, got ${quarter}.`);
    }
    if (!Number.isInteger(slot) || slot < 1 || slot > 3) {
        throw new RangeError(`A month slot is 1 to 3, got ${slot}.`);
    }

    return (quarter - 1) * 3 + slot;
}

/**
 * A slot's column heading. The UI rule (§3): the real month name when exactly one quarter is in
 * view, otherwise "Month 1/2/3".
 *
 * @param months the twelve month names, January first (common.months)
 * @param quartersInView the quarter of every round in view; repeats are fine
 */
export function slotLabel(t: Translate, months: readonly string[], quartersInView: readonly number[], slot: number): string {
    const quarters = [...new Set(quartersInView)];
    const only = quarters[0];
    if (quarters.length === 1 && only !== undefined) {
        const name = months[monthOfSlot(only, slot) - 1];
        if (name !== undefined) {
            return name;
        }
    }

    return t('common.month_slot', { slot });
}
