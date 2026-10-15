import { describe, expect, it } from 'vitest';
import { monthOfSlot, slotLabel } from '@/lib/months';
import { translate } from '@/lib/i18n';
import { testTranslations } from '@/test/render';

// ARCHITECTURE.md §3 "Month-slot mapping", the same table as tests/Unit/Round/MonthSlotTest.php.
const MAPPING: [number, number, number][] = [
    [1, 1, 1], [1, 2, 2], [1, 3, 3],
    [2, 1, 4], [2, 2, 5], [2, 3, 6],
    [3, 1, 7], [3, 2, 8], [3, 3, 9],
    [4, 1, 10], [4, 2, 11], [4, 3, 12],
];

const t = (key: string, replacements?: Record<string, string | number>) => translate(testTranslations, key, replacements);
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

describe('monthOfSlot', () => {
    it.each(MAPPING)('maps Q%i slot %i to month %i', (quarter, slot, month) => {
        expect(monthOfSlot(quarter, slot)).toBe(month);
    });

    it('refuses a quarter or slot that does not exist', () => {
        expect(() => monthOfSlot(5, 1)).toThrow(RangeError);
        expect(() => monthOfSlot(2, 0)).toThrow(RangeError);
        expect(() => monthOfSlot(2, 1.5)).toThrow(RangeError);
    });
});

describe('slotLabel', () => {
    it('names the real month when exactly one quarter is in view (Q2 slot 1 = April)', () => {
        expect(slotLabel(t, MONTHS, [2], 1)).toBe('April');
        expect(slotLabel(t, MONTHS, [4, 4], 3)).toBe('December');
    });

    it('says Month 1/2/3 when several quarters, or none, are in view', () => {
        expect(slotLabel(t, MONTHS, [1, 2], 2)).toBe('Month 2');
        expect(slotLabel(t, MONTHS, [], 3)).toBe('Month 3');
    });
});
