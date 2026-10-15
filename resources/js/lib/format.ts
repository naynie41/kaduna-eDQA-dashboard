/**
 * Counts, decimals, dates and times as people read them in Nigeria (CONVENTION.md §7): en-NG
 * grouping, dates "23 Sep 2026", times on the 24-hour clock in West Africa Time.
 *
 * Never for scores: a score arrives already rounded as ScoreValue.display (Q-09).
 *
 * Month abbreviations come from the language file (common.months_short), not from Intl, which
 * writes "Sept" for September in current ICU data.
 */

const TIME_ZONE = 'Africa/Lagos';
const LOCALE = 'en-NG';
const DATE_ONLY = /^(\d{4})-(\d{2})-(\d{2})$/;

const countFormat = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 });
const partsFormat = new Intl.DateTimeFormat(LOCALE, { timeZone: TIME_ZONE, year: 'numeric', month: 'numeric', day: 'numeric' });
const timeFormat = new Intl.DateTimeFormat(LOCALE, { timeZone: TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });

/** A whole number: 2,184. A fraction is refused, never rounded silently. */
export function formatCount(value: number): string {
    if (!Number.isInteger(value)) {
        throw new RangeError(`formatCount expects a whole number, got ${value}.`);
    }

    return countFormat.format(value);
}

/** A non-score decimal with fixed places: 1,234.5. */
export function formatDecimal(value: number, decimals: number): string {
    return new Intl.NumberFormat(LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(value);
}

/** A band threshold from the rule version: "90", or "87.5" when it is not whole. */
export function formatThreshold(value: number): string {
    return Number.isInteger(value) ? formatCount(value) : formatDecimal(value, 1);
}

/**
 * "23 Sep 2026". A calendar date ("2026-09-23") is shown as written; an instant is read in WAT.
 *
 * @param monthsShort the twelve abbreviations, January first (common.months_short)
 */
export function formatDate(value: string, monthsShort: readonly string[]): string {
    if (monthsShort.length !== 12) {
        throw new RangeError('formatDate needs twelve month names.');
    }

    const [day, month, year] = dateParts(value);

    return `${day} ${monthsShort[month - 1]} ${year}`;
}

/** "14:05 WAT". */
export function formatTime(value: string): string {
    return `${timeFormat.format(instant(value))} WAT`;
}

/** "23 Sep 2026, 14:05 WAT". */
export function formatDateTime(value: string, monthsShort: readonly string[]): string {
    return `${formatDate(value, monthsShort)}, ${formatTime(value)}`;
}

/** [day, month, year] of a calendar date as written, or of an instant in WAT. */
function dateParts(value: string): [number, number, number] {
    const calendar = DATE_ONLY.exec(value);
    if (calendar !== null) {
        return [Number(calendar[3]), Number(calendar[2]), Number(calendar[1])];
    }

    const parts = partsFormat.formatToParts(instant(value));
    const part = (type: Intl.DateTimeFormatPartTypes): number => Number(parts.find((p) => p.type === type)?.value);

    return [part('day'), part('month'), part('year')];
}

function instant(value: string): Date {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        throw new RangeError(`Not a date: "${value}".`);
    }

    return date;
}
