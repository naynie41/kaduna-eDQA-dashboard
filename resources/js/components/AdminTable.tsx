import type { ReactNode } from 'react';
import DataTable, { type DataTableColumn, type DataTableSort } from '@/components/DataTable';
import Pagination, { type PaginationLabels } from '@/components/Pagination';

/** One page of a server-paginated list, as the API's paginator gives it (no arithmetic here). */
export type AdminPageMeta = {
    page: number;
    lastPage: number;
    from: number;
    to: number;
    total: number;
};

export type AdminTableProps = {
    caption: string;
    captionHidden?: boolean;
    columns: readonly DataTableColumn[];
    sort?: DataTableSort;
    onSort?: (column: string) => void;
    /** The rows (<tr>). */
    children: ReactNode;
    /** Shown across the table when there are no rows. */
    empty?: ReactNode;
    meta: AdminPageMeta;
    onPage: (page: number) => void;
    /** What the rows are called in the "Showing …" line and the page navigation. */
    labels: PaginationLabels;
};

/**
 * The administration pages' lists (ARCHITECTURE.md §9.3): the DataTable shell (caption, scoped
 * headers, aria-sort, a keyboard-scrollable region) over one server page, and the pagination
 * under it. Paging and sorting are the server's: the page asks for another page, it is not cut
 * here.
 */
export default function AdminTable({ caption, captionHidden, columns, sort, onSort, children, empty, meta, onPage, labels }: AdminTableProps) {
    return (
        <div>
            <DataTable
                caption={caption}
                columns={columns}
                {...(captionHidden === undefined ? {} : { captionHidden })}
                {...(sort === undefined ? {} : { sort })}
                {...(onSort === undefined ? {} : { onSort })}
                {...(empty === undefined ? {} : { empty })}
            >
                {children}
            </DataTable>
            <Pagination page={meta.page} lastPage={meta.lastPage} from={meta.from} to={meta.to} total={meta.total} onPage={onPage} labels={labels} />
        </div>
    );
}
