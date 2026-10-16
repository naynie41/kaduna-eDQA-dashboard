import { describe, expect, it } from 'vitest';
import { sortRows } from '@/lib/sortRows';

type Row = { name: string; score: number | null };

const rows: Row[] = [
    { name: 'Zaria', score: 83.2 },
    { name: 'Birnin Gwari', score: null },
    { name: 'Chikun', score: 90.5 },
    { name: 'Kauru', score: 78.8 },
    { name: 'Jaba', score: 90.5 },
];

const names = (list: Row[]) => list.map((row) => row.name);

describe('sortRows', () => {
    it('sorts numbers either way, with no data last both times', () => {
        expect(names(sortRows(rows, (r) => r.score, 'asc'))).toEqual(['Kauru', 'Zaria', 'Chikun', 'Jaba', 'Birnin Gwari']);
        expect(names(sortRows(rows, (r) => r.score, 'desc'))).toEqual(['Chikun', 'Jaba', 'Zaria', 'Kauru', 'Birnin Gwari']);
    });

    it('keeps the incoming order for ties (stable)', () => {
        const tied = sortRows(rows, (r) => r.score, 'desc');
        expect(tied.indexOf(rows[2] as Row)).toBeLessThan(tied.indexOf(rows[4] as Row));
    });

    it('sorts names alphabetically, and does not change the input', () => {
        const copy = [...rows];
        expect(names(sortRows(rows, (r) => r.name, 'asc'))).toEqual(['Birnin Gwari', 'Chikun', 'Jaba', 'Kauru', 'Zaria']);
        expect(rows).toEqual(copy);
    });
});
