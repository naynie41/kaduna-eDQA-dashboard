/**
 * Ordering for small, already-aggregated tables (the LGA view: at most 23 rows), where the spec
 * sorts on the client. Only compares; never rounds, bands or combines a value. Rows with no value
 * (no data) always go last, in either direction; ties keep their incoming order (the sort is
 * stable), so the server's name order breaks them.
 */
export type SortDirection = 'asc' | 'desc';

export type SortValue = number | string | null;

export function sortRows<T>(rows: readonly T[], value: (row: T) => SortValue, direction: SortDirection): T[] {
    const sign = direction === 'asc' ? 1 : -1;

    return [...rows].sort((a, b) => {
        const left = value(a);
        const right = value(b);
        if (left === null || right === null) {
            return left === right ? 0 : left === null ? 1 : -1;
        }
        if (typeof left === 'string' || typeof right === 'string') {
            return sign * String(left).localeCompare(String(right), 'en');
        }

        return sign * (left - right);
    });
}
