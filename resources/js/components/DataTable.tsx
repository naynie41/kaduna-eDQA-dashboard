import { Children, type ReactNode } from 'react';
import VisuallyHidden from '@/components/VisuallyHidden';
import { useTranslate } from '@/lib/i18n';

export type DataTableColumn = {
    key: string;
    header: string;
    align?: 'start' | 'end';
    sortable?: boolean;
};

export type DataTableSort = {
    column: string;
    direction: 'asc' | 'desc';
};

export type DataTableProps = {
    /** Names the table and its scroll region. */
    caption: string;
    /** Keep the caption for screen readers only (the panel heading already says it). */
    captionHidden?: boolean;
    columns: readonly DataTableColumn[];
    sort?: DataTableSort;
    onSort?: (column: string) => void;
    /** The rows (<tr>), with a <th scope="row"> where a row has a name. */
    children: ReactNode;
    /** Shown across every column when there are no rows. */
    empty?: ReactNode;
};

/**
 * The table shell every page uses: a caption, column headers with scope, sort buttons with
 * aria-sort on the sorted header, and a horizontally scrolling region that can be reached and
 * scrolled from the keyboard. Sorting itself is the server's (TanStack Table, manual mode).
 */
export default function DataTable({ caption, captionHidden = false, columns, sort, onSort, children, empty }: DataTableProps) {
    const t = useTranslate();
    const hasRows = Children.count(children) > 0;

    return (
        <div role="region" aria-label={t('common.ui.scroll_region', { label: caption })} tabIndex={0} className="overflow-x-auto">
            <table className="w-full border-collapse text-[13px]">
                <caption className={captionHidden ? 'sr-only' : 'px-3 pt-3 text-left text-[12.5px] text-ink-2'}>
                    {caption}
                </caption>
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <HeaderCell key={column.key} column={column} sort={sort} onSort={onSort} />
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {hasRows ? (
                        children
                    ) : (
                        <tr>
                            <td colSpan={columns.length}>{empty ?? <VisuallyHidden>{caption}</VisuallyHidden>}</td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}

type HeaderCellProps = {
    column: DataTableColumn;
    sort: DataTableSort | undefined;
    onSort: ((column: string) => void) | undefined;
};

function HeaderCell({ column, sort, onSort }: HeaderCellProps) {
    const end = column.align === 'end';
    const sortable = column.sortable === true && onSort !== undefined;
    const ariaSort = !sortable ? undefined : sort?.column !== column.key ? 'none' : sort.direction === 'asc' ? 'ascending' : 'descending';
    const arrow = ariaSort === 'ascending' ? '↑' : ariaSort === 'descending' ? '↓' : '↕';

    return (
        <th
            scope="col"
            aria-sort={ariaSort}
            className={`border-b border-rule px-3 py-2.5 text-[11.5px] font-medium whitespace-nowrap text-ink-2 ${end ? 'text-right' : 'text-left'}`}
        >
            {sortable ? (
                <button type="button" onClick={() => onSort(column.key)} className={`inline-flex items-center gap-1 hover:text-ink ${end ? 'flex-row-reverse' : ''}`}>
                    {column.header}
                    <span aria-hidden="true">{arrow}</span>
                </button>
            ) : (
                column.header
            )}
        </th>
    );
}
