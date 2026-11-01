import { Children, type ReactNode } from 'react';
import VisuallyHidden from '@/components/VisuallyHidden';
import { useTranslate } from '@/lib/i18n';

export type DataTableColumn = {
    key: string;
    header: string;
    align?: 'start' | 'end';
    sortable?: boolean;
    /** Read after the header by screen readers only, e.g. "of the quarter" after "Month 1". */
    srSuffix?: string;
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
            <table className="w-full border-collapse text-sm [&_td:first-child]:pl-5 [&_td:last-child]:pr-5 [&_th:first-child]:pl-5 [&_th:last-child]:pr-5 [&_tbody_tr]:transition-colors [&_tbody_tr]:duration-150 [&_tbody_tr:hover]:bg-paper/50">
                <caption className={captionHidden ? 'sr-only' : 'px-5 pt-3 pb-1 text-left text-xs text-ink-2'}>
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

/** Both chevrons faint when unsorted; the active direction solid. Decorative: aria-sort says it. */
function SortIcon({ state }: { state: 'ascending' | 'descending' | 'none' }) {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 10 12" className="size-2.5 shrink-0" fill="currentColor">
            <path d="M5 1.5 8.5 5h-7z" opacity={state === 'ascending' ? 1 : 0.3} />
            <path d="M5 10.5 1.5 7h7z" opacity={state === 'descending' ? 1 : 0.3} />
        </svg>
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
    return (
        <th
            scope="col"
            aria-sort={ariaSort}
            className={`border-y border-rule bg-paper/60 px-3 py-2 align-bottom text-2xs leading-snug font-semibold text-ink-2 ${end ? 'text-right' : 'text-left'}`}
        >
            {sortable ? (
                <button
                    type="button"
                    onClick={() => onSort(column.key)}
                    className={`-mx-1 inline-flex min-h-6.5 items-center gap-1 rounded-sm px-1 py-1 transition-colors duration-150 hover:bg-wash hover:text-ink ${ariaSort !== 'none' ? 'text-ink' : ''} ${end ? 'flex-row-reverse' : ''}`}
                >
                    <span>
                        {column.header}
                        {column.srSuffix !== undefined && (
                            <>
                                {' '}
                                <VisuallyHidden>{column.srSuffix}</VisuallyHidden>
                            </>
                        )}
                    </span>
                    <SortIcon state={ariaSort ?? 'none'} />
                </button>
            ) : (
                <>
                    {column.header}
                    {column.srSuffix !== undefined && (
                            <>
                                {' '}
                                <VisuallyHidden>{column.srSuffix}</VisuallyHidden>
                            </>
                        )}
                </>
            )}
        </th>
    );
}
