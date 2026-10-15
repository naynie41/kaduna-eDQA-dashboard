import { describe, expect, it } from 'vitest';
import { formatCount, formatDate, formatDateTime, formatDecimal, formatThreshold, formatTime } from '@/lib/format';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

describe('formatCount', () => {
    it('groups thousands the en-NG way', () => {
        expect(formatCount(2184)).toBe('2,184');
        expect(formatCount(0)).toBe('0');
        expect(formatCount(1234567)).toBe('1,234,567');
    });

    it('refuses a fraction rather than round it silently', () => {
        expect(() => formatCount(2.5)).toThrow(RangeError);
    });
});

describe('formatDecimal', () => {
    it('fixes the decimals and groups thousands', () => {
        expect(formatDecimal(1234.5, 1)).toBe('1,234.5');
        expect(formatDecimal(19.1304, 1)).toBe('19.1');
        expect(formatDecimal(7, 2)).toBe('7.00');
    });
});

describe('formatThreshold', () => {
    it('writes a whole threshold without decimals, and any other with one', () => {
        expect(formatThreshold(90)).toBe('90');
        expect(formatThreshold(87.5)).toBe('87.5');
    });
});

describe('formatDate', () => {
    it('shows a calendar date as 23 Sep 2026, never shifted by a time zone', () => {
        expect(formatDate('2026-09-23', MONTHS)).toBe('23 Sep 2026');
        expect(formatDate('2026-01-01', MONTHS)).toBe('1 Jan 2026');
        expect(formatDate('2026-12-31', MONTHS)).toBe('31 Dec 2026');
    });

    it('reads an instant in West Africa Time', () => {
        // 23:30 UTC on 30 June is 00:30 on 1 July in Lagos.
        expect(formatDate('2026-06-30T23:30:00Z', MONTHS)).toBe('1 Jul 2026');
        expect(formatDate('2026-09-23T09:00:00+01:00', MONTHS)).toBe('23 Sep 2026');
    });

    it('uses the month names it is given, from the language file', () => {
        expect(formatDate('2026-09-23', ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'])).toBe('23 S 2026');
    });

    it('refuses an unreadable date or a month list that is not twelve long', () => {
        expect(() => formatDate('soon', MONTHS)).toThrow(RangeError);
        expect(() => formatDate('2026-09-23', ['Jan'])).toThrow(RangeError);
    });
});

describe('formatTime and formatDateTime', () => {
    it('shows times on the 24-hour clock in WAT', () => {
        expect(formatTime('2026-09-23T13:05:00Z')).toBe('14:05 WAT');
        expect(formatDateTime('2026-09-23T13:05:00Z', MONTHS)).toBe('23 Sep 2026, 14:05 WAT');
    });
});
