import type { AdminTableProps } from '@/components/AdminTable';
import { useTranslate } from '@/lib/i18n';
import type { DataIssueRow, Paginated, RoundRef } from '@/types/admin';

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

/** A page of records as AdminTable's pager reads it. */
export function tableMeta(records: Paginated<unknown>): AdminTableProps['meta'] {
    return { page: records.current_page, lastPage: records.last_page, from: records.from ?? 0, to: records.to ?? 0, total: records.total };
}

/** The Data issues tables' pager wording. */
export function useTableLabels(): AdminTableProps['labels'] {
    const t = useTranslate();

    return {
        showing: (figures) => t('admin.issues.table.showing', figures),
        none: t('admin.issues.table.showing_none'),
        nav: t('admin.issues.table.pages'),
    };
}

/** "Q2 2026", or "No round" for a record no round could be read for. */
export function useRoundLabel(): (round: RoundRef | null) => string {
    const t = useTranslate();

    return (round) => (round === null ? t('admin.issues.table.no_round') : t('admin.issues.filters.round_option', { quarter: round.quarter, year: round.year }));
}
