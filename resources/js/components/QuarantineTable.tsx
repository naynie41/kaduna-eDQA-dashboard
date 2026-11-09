import { Fragment } from 'react';
import AdminTable from '@/components/AdminTable';
import { useAnnounce } from '@/components/Announcer';
import CorrectIssueForm from '@/components/CorrectIssueForm';
import DuplicateSides from '@/components/DuplicateSides';
import SourceTag from '@/components/SourceTag';
import { tableMeta, useRoundLabel, useTableLabels } from '@/lib/dataIssues';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { CorrectableField, DataIssueRow, Paginated } from '@/types/admin';

export type QuarantineTableProps = {
    records: Paginated<DataIssueRow>;
    lgas: { code: string; name: string }[];
    /** The open records ticked for a bulk action. */
    selected: number[];
    onSelectedChange: (ids: number[]) => void;
    /** The record whose correction form is open under its row, if any. */
    correcting: number | null;
    onCorrectingChange: (id: number | null) => void;
    onReject: (row: DataIssueRow) => void;
    onCorrect: (row: DataIssueRow, corrections: Partial<Record<CorrectableField, string | number | null>>) => void;
    /** A cross-source duplicate (§5.8): keep the accepted assessment, or let this one replace it. */
    onKeepExisting: (row: DataIssueRow) => void;
    onUseThis: (row: DataIssueRow) => void;
    onDetails: (row: DataIssueRow) => void;
    onPage: (page: number) => void;
};

/**
 * The Data issues page's Hard tab (ARCHITECTURE.md §9.3, D-57): each quarantined record with its
 * source, the rules it fails and why, and its actions. An open record can be ticked for a bulk
 * action, corrected in a form under its row, or rejected; a duplicate shows both sides. The page
 * asks for the reason and sends the resolution.
 */
export default function QuarantineTable({ records, lgas, selected, onSelectedChange, correcting, onCorrectingChange, onReject, onCorrect, onKeepExisting, onUseThis, onDetails, onPage }: QuarantineTableProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const roundLabel = useRoundLabel();
    const labels = useTableLabels();
    const when = (value: string | null) => (value === null ? '' : months.length === 12 ? formatDateTime(value, months) : value);
    const button = 'btn btn-secondary min-h-11';
    const copy = (instance: string) => {
        void navigator.clipboard?.writeText(instance).then(() => announce(t('admin.issues.table.copied')));
    };

    return (
        <AdminTable
            caption={t('admin.issues.table.caption')}
            captionHidden
            columns={[
                { key: 'select', header: '', srSuffix: t('admin.issues.table.select_header') },
                { key: 'instance', header: t('admin.issues.table.instance') },
                { key: 'source', header: t('admin.issues.table.source') },
                { key: 'rules', header: t('admin.issues.table.rules') },
                { key: 'facility', header: t('admin.issues.table.facility') },
                { key: 'lga', header: t('admin.issues.table.lga') },
                { key: 'round', header: t('admin.issues.table.round') },
                { key: 'detail', header: t('admin.issues.table.detail') },
                { key: 'received', header: t('admin.issues.table.received') },
                { key: 'actions', header: t('admin.issues.table.actions') },
            ]}
            empty={<p className="px-3 py-6 text-center text-sm text-ink-2">{t('admin.issues.table.none')}</p>}
            meta={tableMeta(records)}
            onPage={onPage}
            labels={labels}
        >
            {records.data.map((row) => (
                <Fragment key={row.id}>
                    <tr className="border-b border-rule align-top">
                        <td className="px-3 py-2">
                            {row.status === 'open' && (
                                <input
                                    type="checkbox"
                                    className="h-5 w-5"
                                    aria-label={t('admin.issues.table.select', { instance: row.instance_id })}
                                    checked={selected.includes(row.id)}
                                    onChange={(event) => onSelectedChange(event.target.checked ? [...selected, row.id] : selected.filter((id) => id !== row.id))}
                                />
                            )}
                        </td>
                        <th scope="row" className="px-3 py-2 text-left font-normal">
                            <code className="block max-w-56 text-xs break-all">{row.instance_id}</code>
                            <button type="button" className="mt-1 text-xs text-deep underline" onClick={() => copy(row.instance_id)} aria-label={t('admin.issues.table.copy', { instance: row.instance_id })}>
                                {t('admin.issues.table.copy_short')}
                            </button>
                        </th>
                        <td className="px-3 py-2 whitespace-nowrap">
                            <SourceTag source={row.source ?? null} />
                        </td>
                        <td className="px-3 py-2">
                            <ul className="flex flex-wrap gap-1">
                                {row.failures.map((failure, i) => (
                                    <li key={`${failure.code}-${i}`}>
                                        <code className="rounded bg-bad-bg px-1.5 py-px text-2xs text-bad">{failure.code}</code>
                                    </li>
                                ))}
                            </ul>
                        </td>
                        <td className="px-3 py-2">
                            {row.facility_ref === null ? '–' : <code className="text-xs">{row.facility_ref}</code>}
                            <span className="block text-xs text-ink-2">{row.facility_ref === null ? '' : (row.facility_name ?? t('admin.issues.table.facility_unknown'))}</span>
                        </td>
                        <td className="px-3 py-2">{row.lga_ref ?? '–'}</td>
                        <td className="px-3 py-2 whitespace-nowrap">{roundLabel(row.round)}</td>
                        <td className="px-3 py-2">
                            <ul className="flex max-w-80 flex-col gap-1">
                                {row.failures.map((failure, i) => (
                                    <li key={`${failure.code}-${i}`}>
                                        <code className="text-xs break-words">{failure.detail}</code>
                                        {failure.code === 'DUPLICATE_ASSESSMENT' && <DuplicateSides row={row} />}
                                    </li>
                                ))}
                            </ul>
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap">{when(row.received_at)}</td>
                        <td className="px-3 py-2">
                            <div className="flex flex-col items-start gap-1.5">
                                {row.status === 'open' && row.duplicate_of !== null && row.failures.every((failure) => failure.code === 'DUPLICATE_ASSESSMENT') && (
                                    <>
                                        <button type="button" className="btn btn-primary min-h-11" onClick={() => onKeepExisting(row)}>
                                            {t('admin.issues.actions.keep_existing')}
                                        </button>
                                        <button type="button" className={button} onClick={() => onUseThis(row)}>
                                            {t('admin.issues.actions.use_this')}
                                        </button>
                                    </>
                                )}
                                {row.status === 'open' ? (
                                    <>
                                        <button type="button" className={button} aria-expanded={correcting === row.id} onClick={() => onCorrectingChange(correcting === row.id ? null : row.id)}>
                                            {t('admin.issues.actions.correct')}
                                        </button>
                                        <button type="button" className={button} onClick={() => onReject(row)}>
                                            {t('admin.issues.actions.reject')}
                                        </button>
                                    </>
                                ) : (
                                    <span className="text-xs text-ink-2">
                                        {t('admin.issues.table.resolved', { resolution: t(`admin.issues.table.resolutions.${row.resolution ?? 'reprocessed'}`), time: when(row.resolved_at) })}
                                    </span>
                                )}
                                <button type="button" className={button} onClick={() => onDetails(row)}>
                                    {t('admin.issues.actions.details')}
                                </button>
                                {row.source !== null && row.source_url !== null && (
                                    <a href={row.source_url} target="_blank" rel="noopener noreferrer" className="text-xs text-deep underline">
                                        {t('admin.issues.actions.open_in_source', { source: row.source.name })} <span className="sr-only">{t('admin.issues.actions.new_tab')}</span>
                                    </a>
                                )}
                            </div>
                        </td>
                    </tr>
                    {correcting === row.id && (
                        <tr className="border-b border-rule">
                            <td colSpan={10} className="px-3 py-3">
                                <CorrectIssueForm row={row} lgas={lgas} onReview={(corrections) => onCorrect(row, corrections)} onCancel={() => onCorrectingChange(null)} />
                            </td>
                        </tr>
                    )}
                </Fragment>
            ))}
        </AdminTable>
    );
}
