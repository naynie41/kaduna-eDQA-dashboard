import AdminTable from '@/components/AdminTable';
import SourceTag from '@/components/SourceTag';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { DeletedAssessmentRow, Paginated } from '@/types/admin';

export type DeletedAtSourceTableProps = {
    records: Paginated<DeletedAssessmentRow>;
    /** "Keep — the deletion was a mistake at source": marks it reviewed (a reason, in the page's dialog). */
    onKeep: (row: DeletedAssessmentRow) => void;
    onWithdraw: (row: DeletedAssessmentRow) => void;
    onRestore: (row: DeletedAssessmentRow) => void;
    onPage: (page: number) => void;
};

/**
 * The Data issues page's Deleted at source tab (D-24): each assessment whose submission its source
 * no longer returns, with its source, round and what it counts as now. While unreviewed it can be
 * kept (the deletion was a mistake at source) or withdrawn (it then counts nowhere); a withdrawn
 * one can be restored. Every action asks for a reason in the page's dialog. A closed round's
 * assessments are fixed (D-32), so its rows offer no action.
 */
export default function DeletedAtSourceTable({ records, onKeep, onWithdraw, onRestore, onPage }: DeletedAtSourceTableProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);

    return (
        <AdminTable
            caption={t('admin.issues.deleted.caption')}
            captionHidden
            columns={[
                { key: 'facility', header: t('admin.issues.deleted.facility') },
                { key: 'source', header: t('admin.issues.deleted.source') },
                { key: 'lga', header: t('admin.issues.deleted.lga') },
                { key: 'round', header: t('admin.issues.deleted.round') },
                { key: 'deleted', header: t('admin.issues.deleted.deleted') },
                { key: 'assessment', header: t('admin.issues.deleted.assessment') },
                { key: 'actions', header: t('admin.issues.deleted.actions') },
            ]}
            empty={<p className="px-3 py-6 text-center text-sm text-ink-2">{t('admin.issues.deleted.none')}</p>}
            meta={{ page: records.current_page, lastPage: records.last_page, from: records.from ?? 0, to: records.to ?? 0, total: records.total }}
            onPage={onPage}
            labels={{
                showing: (figures) => t('admin.issues.table.showing', figures),
                none: t('admin.issues.table.showing_none'),
                nav: t('admin.issues.table.pages'),
            }}
        >
            {records.data.map((row) => {
                const facility = { facility: row.facility.name };
                const withdrawn = row.assessment.status === 'withdrawn';

                return (
                    <tr key={row.submission_id} className="border-b border-rule align-top">
                        <th scope="row" className="px-3 py-2 text-left font-normal">
                            {row.facility.name}
                            <code className="block text-xs text-ink-2">{row.facility.code}</code>
                        </th>
                        <td className="px-3 py-2">
                            <SourceTag source={row.source} />
                            <code className="mt-1 block max-w-56 text-xs break-all">{row.instance_id}</code>
                            {row.source !== null && row.source_url !== null && (
                                <a href={row.source_url} target="_blank" rel="noopener noreferrer" className="text-xs text-deep underline">
                                    {t('admin.issues.actions.open_in_source', { source: row.source.name })} <span className="sr-only">{t('admin.issues.actions.new_tab')}</span>
                                </a>
                            )}
                        </td>
                        <td className="px-3 py-2">{row.lga}</td>
                        <td className="px-3 py-2 whitespace-nowrap">{t('admin.issues.filters.round_option', { quarter: row.round.quarter, year: row.round.year })}</td>
                        <td className="px-3 py-2 whitespace-nowrap">{when(row.deleted_at)}</td>
                        <td className="px-3 py-2">
                            <span className={withdrawn ? 'font-medium text-bad' : ''}>{t(`admin.issues.deleted.statuses.${row.assessment.status}`)}</span>
                            {row.reviewed !== null && <span className="mt-0.5 block text-xs text-ink-2">{t('admin.issues.deleted.reviewed', { name: row.reviewed.by ?? '—', time: when(row.reviewed.at) })}</span>}
                        </td>
                        <td className="px-3 py-2">
                            {!row.round.open ? (
                                <span className="text-xs text-ink-2">{t('admin.issues.deleted.closed')}</span>
                            ) : (
                                <div className="flex flex-col items-start gap-1.5">
                                    {!withdrawn && row.reviewed === null && (
                                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => onKeep(row)}>
                                            <span aria-hidden="true">{t('admin.issues.deleted.keep')}</span>
                                            <span className="sr-only">{t('admin.issues.deleted.keep_named', facility)}</span>
                                        </button>
                                    )}
                                    {!withdrawn && (
                                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => onWithdraw(row)}>
                                            <span aria-hidden="true">{t('admin.issues.deleted.withdraw')}</span>
                                            <span className="sr-only">{t('admin.issues.deleted.withdraw_named', facility)}</span>
                                        </button>
                                    )}
                                    {withdrawn && (
                                        <button type="button" className="btn btn-secondary btn-sm" onClick={() => onRestore(row)}>
                                            <span aria-hidden="true">{t('admin.issues.deleted.restore')}</span>
                                            <span className="sr-only">{t('admin.issues.deleted.restore_named', facility)}</span>
                                        </button>
                                    )}
                                </div>
                            )}
                        </td>
                    </tr>
                );
            })}
        </AdminTable>
    );
}
