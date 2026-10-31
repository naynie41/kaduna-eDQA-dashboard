import { Link } from '@inertiajs/react';
import DataTable from '@/components/DataTable';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { DeletionRow } from '@/types/admin';

export type DeletionsPanelProps = {
    deletions: { rows: DeletionRow[]; total: number };
};

/**
 * Deletions at source (ARCHITECTURE.md §5.3, §9.3, D-62): the submissions a source no longer
 * returns, newest first, for an administrator to review; nothing is removed by the portal. Each
 * row links to the record it touches: the assessment built from it (the facility drawer, in its
 * round) or Data issues when it is in quarantine, and the submission on its source.
 */
export default function DeletionsPanel({ deletions }: DeletionsPanelProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);
    const { rows, total } = deletions;

    return (
        <Panel labelledBy="sources-deletions-title">
            <PanelHeader id="sources-deletions-title" title={t('admin.sources.deletions.title')} description={t('admin.sources.deletions.description')} />
            <DataTable
                caption={t('admin.sources.deletions.caption')}
                captionHidden
                columns={[
                    { key: 'detected', header: t('admin.sources.deletions.detected') },
                    { key: 'source', header: t('admin.sources.deletions.source') },
                    { key: 'instance', header: t('admin.sources.deletions.instance') },
                    { key: 'facility', header: t('admin.sources.deletions.facility') },
                    { key: 'round', header: t('admin.sources.deletions.round') },
                    { key: 'portal', header: t('admin.sources.deletions.in_portal') },
                ]}
                empty={<p className="px-3 py-6 text-center text-[13px] text-ink-2">{t('admin.sources.deletions.none')}</p>}
            >
                {rows.map((row) => (
                    <tr key={row.submission_id} className="border-b border-rule align-top last:border-b-0">
                        <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                            {when(row.deleted_at)}
                        </th>
                        <td className="px-3 py-2 whitespace-nowrap">{row.source?.name ?? t('admin.sources.history.unknown_source')}</td>
                        <td className="px-3 py-2">
                            <code className="block max-w-56 text-[12px] break-all">{row.instance_id}</code>
                            {row.source !== null && row.source_url !== null && (
                                <a href={row.source_url} target="_blank" rel="noopener noreferrer" className="text-[12.5px] text-deep underline">
                                    {t('admin.sources.deletions.open_in_source', { source: row.source.name })} <span className="sr-only">{t('admin.sources.deletions.new_tab')}</span>
                                </a>
                            )}
                        </td>
                        <td className="px-3 py-2">
                            {row.facility === null ? (
                                '–'
                            ) : (
                                <>
                                    {row.facility.name}
                                    <code className="block text-[12px] text-ink-2">{row.facility.code}</code>
                                </>
                            )}
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap">{row.round === null ? '–' : t('admin.sources.deletions.round_value', { quarter: row.round.quarter, year: row.round.year })}</td>
                        <td className="px-3 py-2">
                            <RecordLink row={row} />
                        </td>
                    </tr>
                ))}
            </DataTable>
            {total > rows.length && <p className="px-5 pt-2 pb-4 text-[12.5px] text-ink-2">{t('admin.sources.deletions.more', { shown: formatCount(rows.length), total: formatCount(total) })}</p>}
        </Panel>
    );
}

function RecordLink({ row }: { row: DeletionRow }) {
    const t = useTranslate();

    if (row.assessment !== null && row.facility !== null && row.round !== null) {
        const href = `/dashboard/facility?year=${row.round.year}&quarter=${row.round.quarter}&facility=${row.facility.id}`;

        return (
            <span className="flex flex-col gap-0.5">
                <span>{t('admin.sources.deletions.assessment', { status: t(`enums.submission_status.${row.assessment.status}`) })}</span>
                <Link href={href} className="text-[12.5px] text-deep underline">
                    {t('admin.sources.deletions.open_assessment', { facility: row.facility.name })}
                </Link>
            </span>
        );
    }
    if (row.quarantined_record_id !== null) {
        const href = row.round === null ? '/admin/data-issues' : `/admin/data-issues?round=${row.round.id}`;

        return (
            <span className="flex flex-col gap-0.5">
                <span>{t('admin.sources.deletions.quarantined')}</span>
                <Link href={href} className="text-[12.5px] text-deep underline">
                    {t('admin.sources.deletions.open_issues')}
                </Link>
            </span>
        );
    }

    return <span className="text-ink-2">{t('admin.sources.deletions.nothing')}</span>;
}
