import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import DataTable, { type DataTableColumn } from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import HeatCell from '@/components/HeatCell';
import ScoreTag from '@/components/ScoreTag';
import { formatDate } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { slotLabel } from '@/lib/months';
import type { FacilityRow, FacilitySortColumn, FacilityTableState } from '@/types/dashboard';

export type FacilityTableProps = {
    state: FacilityTableState;
    caption: string;
    /** Ask the server for this order (a partial reload); the table never sorts rows itself. */
    onSort: (column: FacilitySortColumn) => void;
    /** Open the facility's detail drawer. */
    onOpen: (row: FacilityRow) => void;
};

const helper = createColumnHelper<FacilityRow>();

const SORTABLE: readonly string[] = ['facility', 'lga', 'ward', 'owner', 'assessed', 'm1', 'm2', 'm3', 'score'];

function isSortColumn(value: string): value is FacilitySortColumn {
    return SORTABLE.includes(value);
}

/**
 * The facility table (ARCHITECTURE.md §9.2.2): TanStack Table in manual mode for the column model
 * and sort state, rendered through DataTable (caption, scoped headers, aria-sort buttons, the
 * keyboard-reachable scroll region). Sorting and paging happen on the server; the rows arrive
 * already in order. Each facility name is a button opening its detail drawer.
 */
export default function FacilityTable({ state, caption, onSort, onOpen }: FacilityTableProps) {
    'use no memo'; // TanStack keeps one mutable table instance: the React Compiler must not memoise around it

    const t = useTranslate();
    const months = useTranslationList('common.months');
    const monthsShort = useTranslationList('common.months_short');
    const named = new Set(state.quarters).size === 1;
    const scoreHeader = state.metric === 'overall' ? t('dashboard.facility.overall') : t(`enums.dimension.${state.metric}`);

    const columns = [
        helper.accessor('name', { id: 'facility', header: t('dashboard.facility.facility') }),
        helper.accessor('lga', { id: 'lga', header: t('dashboard.facility.lga') }),
        helper.accessor('ward', { id: 'ward', header: t('dashboard.facility.ward') }),
        helper.accessor('ownership', { id: 'owner', header: t('dashboard.facility.owner') }),
        helper.accessor('assessedOn', { id: 'assessed', header: t('dashboard.facility.assessed') }),
        helper.accessor('m1', { id: 'm1', header: slotLabel(t, months, state.quarters, 1) }),
        helper.accessor('m2', { id: 'm2', header: slotLabel(t, months, state.quarters, 2) }),
        helper.accessor('m3', { id: 'm3', header: slotLabel(t, months, state.quarters, 3) }),
        helper.accessor('score', { id: 'score', header: scoreHeader }),
    ];

    const sorting: SortingState = [{ id: state.sort.column, desc: state.sort.direction === 'desc' }];
    // eslint-disable-next-line react-hooks/incompatible-library -- opted out of compilation above ('use no memo')
    const table = useReactTable({
        data: state.page.rows,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getRowId: (row) => String(row.facilityId),
        manualSorting: true,
        manualPagination: true,
        rowCount: state.page.total,
        state: { sorting },
    });

    const shellColumns: DataTableColumn[] = table.getFlatHeaders().map((header) => ({
        key: header.column.id,
        header: String(header.column.columnDef.header),
        sortable: isSortColumn(header.column.id),
        ...(['m1', 'm2', 'm3', 'score'].includes(header.column.id) ? { align: 'end' as const } : {}),
        ...(!named && ['m1', 'm2', 'm3'].includes(header.column.id) ? { srSuffix: t('dashboard.facility.of_quarter') } : {}),
    }));

    return (
        <DataTable
            caption={caption}
            captionHidden
            columns={shellColumns}
            sort={{ column: state.sort.column, direction: state.sort.direction }}
            onSort={(column) => isSortColumn(column) && onSort(column)}
            empty={<EmptyState title={t('dashboard.facility.showing_none')} />}
        >
            {table.getRowModel().rows.map((row) => {
                const facility = row.original;

                return (
                    <tr key={row.id} className="border-b border-paper last:border-b-0">
                        {row.getVisibleCells().map((cell) => {
                            switch (cell.column.id) {
                                case 'facility':
                                    return (
                                        <th key={cell.id} scope="row" className="px-3 py-2 text-left font-normal">
                                            <button
                                                type="button"
                                                aria-haspopup="dialog"
                                                onClick={() => onOpen(facility)}
                                                className="text-left font-medium text-deep underline decoration-rule underline-offset-2 hover:decoration-deep"
                                            >
                                                {facility.name}
                                            </button>
                                            <span className="block text-[11.5px] text-ink-2">{facility.code}</span>
                                        </th>
                                    );
                                case 'owner':
                                    return (
                                        <td key={cell.id} className="px-3 py-2">
                                            <span className={`rounded-full px-2 py-0.5 text-[11.5px] ${facility.ownership === 'public' ? 'bg-pub-bg text-pub' : 'bg-priv-bg text-priv'}`}>
                                                {t(`enums.ownership.${facility.ownership}`)}
                                            </span>
                                        </td>
                                    );
                                case 'assessed':
                                    return (
                                        <td key={cell.id} className="px-3 py-2 whitespace-nowrap tabular-nums">
                                            {facility.roundLabel}
                                            <span className="block text-[11.5px] text-ink-2">{monthsShort.length === 12 ? formatDate(facility.assessedOn, monthsShort) : facility.assessedOn}</span>
                                        </td>
                                    );
                                case 'm1':
                                case 'm2':
                                case 'm3':
                                    return <HeatCell key={cell.id} score={facility[cell.column.id]} />;
                                case 'score':
                                    return (
                                        <td key={cell.id} className="px-3 py-2 text-right">
                                            <ScoreTag score={facility.score} />
                                        </td>
                                    );
                                default:
                                    return (
                                        <td key={cell.id} className="px-3 py-2">
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </td>
                                    );
                            }
                        })}
                    </tr>
                );
            })}
        </DataTable>
    );
}
