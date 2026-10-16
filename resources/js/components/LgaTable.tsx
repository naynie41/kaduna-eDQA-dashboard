import { useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import DataTable, { type DataTableColumn, type DataTableSort } from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import HeatCell from '@/components/HeatCell';
import StatusLabel from '@/components/StatusLabel';
import { formatCount } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { slotLabel } from '@/lib/months';
import { sortRows, type SortValue } from '@/lib/sortRows';
import type { LgaRow, RoundRef } from '@/types/dashboard';

export type LgaTableProps = {
    lgas: LgaRow[];
    rounds: RoundRef[];
    /** Says what the table covers: the filter's scope. */
    caption: string;
};

type Key = 'name' | 'facilities' | 'availability' | 'consistency' | 'validity' | 'm1' | 'm2' | 'm3' | 'overall';

const SCORE_KEYS = ['availability', 'consistency', 'validity', 'm1', 'm2', 'm3'] as const;

function sortValue(row: LgaRow, key: Key): SortValue {
    if (key === 'name') {
        return row.name;
    }
    if (key === 'facilities') {
        return row.facilities;
    }

    return row[key].value;
}

function isKey(value: string): value is Key {
    return ['name', 'facilities', 'availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall'].includes(value);
}

/**
 * "All four reports by local government" (ARCHITECTURE.md §9.2.3): every LGA in scope with its
 * facilities, the three dimensions and the three month slots as heat cells, the overall and its
 * status in words. At most 23 rows of server figures, so it sorts on the client (aria-sort on
 * the header, the change announced). Months are named when one quarter is in view.
 */
export default function LgaTable({ lgas, rounds, caption }: LgaTableProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months');
    const [sort, setSort] = useState<{ column: Key; direction: 'asc' | 'desc' }>({ column: 'overall', direction: 'desc' });
    const quarters = rounds.map((round) => round.quarter);
    const named = new Set(quarters).size === 1;

    const columns: DataTableColumn[] = [
        { key: 'name', header: t('dashboard.lga.lga'), sortable: true },
        { key: 'facilities', header: t('dashboard.lga.facilities'), align: 'end', sortable: true },
        { key: 'availability', header: t('enums.dimension.availability'), align: 'end', sortable: true },
        { key: 'consistency', header: t('enums.dimension.consistency'), align: 'end', sortable: true },
        { key: 'validity', header: t('enums.dimension.validity'), align: 'end', sortable: true },
        ...([1, 2, 3] as const).map((slot) => ({
            key: `m${slot}`,
            header: slotLabel(t, months, quarters, slot),
            align: 'end' as const,
            sortable: true,
            ...(named ? {} : { srSuffix: t('dashboard.lga.of_quarter') }),
        })),
        { key: 'overall', header: t('dashboard.lga.overall'), align: 'end', sortable: true },
        { key: 'status', header: t('dashboard.lga.status') },
    ];

    const onSort = (column: string) => {
        if (!isKey(column)) {
            return;
        }
        // A new column starts ascending: names A–Z, scores weakest first (as the facility view).
        const direction = sort.column === column && sort.direction === 'asc' ? 'desc' : 'asc';
        setSort({ column, direction });
        const header = columns.find((c) => c.key === column)?.header ?? column;
        announce(t('dashboard.lga.sorted', { column: header, direction: t(direction === 'asc' ? 'dashboard.lga.ascending' : 'dashboard.lga.descending') }));
    };

    const rows = sortRows(lgas, (row) => sortValue(row, sort.column), sort.direction);
    const tableSort: DataTableSort = { column: sort.column, direction: sort.direction };

    return (
        <DataTable caption={caption} columns={columns} sort={tableSort} onSort={onSort} empty={<EmptyState title={t('dashboard.lga.nothing')} />}>
            {rows.map((row) => (
                <tr key={row.code} className="border-b border-paper last:border-b-0">
                    <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                        {row.name}
                    </th>
                    <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.facilities)}</td>
                    {SCORE_KEYS.map((key) => (
                        <HeatCell key={key} score={row[key]} />
                    ))}
                    <td className="px-3 py-2 text-right font-medium tabular-nums">{row.overall.display}</td>
                    <td className="px-3 py-2">
                        <StatusLabel band={row.overall.band} label={row.overall.bandLabel} />
                    </td>
                </tr>
            ))}
        </DataTable>
    );
}
