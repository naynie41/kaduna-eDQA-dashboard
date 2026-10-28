import type { DataIssueRow } from '@/types/admin';

/**
 * The rule a bulk action would resolve, or null when bulk is not allowed (D-57): every selected
 * record must be open and fail exactly one rule, the same one. The server refuses mixed rule sets
 * (BulkResolveQuarantine); the page asks for a single rule, as §9.3 says.
 */
export function bulkRule(rows: Pick<DataIssueRow, 'status' | 'failures'>[]): string | null {
    const first = rows[0]?.failures[0]?.code;
    if (first === undefined) {
        return null;
    }

    return rows.every((row) => row.status === 'open' && row.failures.length === 1 && row.failures[0]?.code === first) ? first : null;
}
