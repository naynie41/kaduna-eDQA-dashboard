import AdminTable from '@/components/AdminTable';
import SourceTag from '@/components/SourceTag';
import { tableMeta, useRoundLabel, useTableLabels } from '@/lib/dataIssues';
import { formatDate } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { FlaggedRow, Paginated } from '@/types/admin';

export type FlaggedTableProps = {
    records: Paginated<FlaggedRow>;
    /** Override one flag on one assessment: the page asks for the reason. */
    onOverride: (row: FlaggedRow, code: string, name: string) => void;
    onPage: (page: number) => void;
};

/** The Data issues page's Soft tab (ARCHITECTURE.md §9.3): accepted assessments and their flags. */
export default function FlaggedTable({ records, onOverride, onPage }: FlaggedTableProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const roundLabel = useRoundLabel();
    const labels = useTableLabels();

    return (
        <AdminTable
            caption={t('admin.issues.table.soft_caption')}
            captionHidden
            columns={[
                { key: 'facility', header: t('admin.issues.table.facility') },
                { key: 'lga', header: t('admin.issues.table.lga') },
                { key: 'round', header: t('admin.issues.table.round') },
                { key: 'source', header: t('admin.issues.table.source') },
                { key: 'assessor', header: t('admin.issues.table.assessor') },
                { key: 'visited', header: t('admin.issues.table.visited') },
                { key: 'flags', header: t('admin.issues.table.flags') },
            ]}
            empty={<p className="px-3 py-6 text-center text-sm text-ink-2">{t('admin.issues.table.none')}</p>}
            meta={tableMeta(records)}
            onPage={onPage}
            labels={labels}
        >
            {records.data.map((row) => (
                <tr key={row.id} className="border-b border-rule align-top">
                    <th scope="row" className="px-3 py-2 text-left font-normal">
                        {row.facility.name}
                        <code className="block text-xs text-ink-2">{row.facility.code}</code>
                    </th>
                    <td className="px-3 py-2">{row.lga?.name ?? '–'}</td>
                    <td className="px-3 py-2 whitespace-nowrap">{roundLabel(row.round)}</td>
                    <td className="px-3 py-2 whitespace-nowrap">
                        <SourceTag source={row.source ?? null} />
                    </td>
                    <td className="px-3 py-2">{row.assessor}</td>
                    <td className="px-3 py-2 whitespace-nowrap">{months.length === 12 ? formatDate(row.started_at, months) : row.started_at}</td>
                    <td className="px-3 py-2">
                        <ul className="flex flex-col gap-1.5">
                            {row.flags.map((flag) => (
                                <li key={flag.code} className="flex flex-wrap items-center gap-2">
                                    <code className="rounded bg-warn-bg px-1.5 py-px text-2xs text-warn">{flag.code}</code>
                                    <button type="button" className="btn btn-secondary min-h-11" onClick={() => onOverride(row, flag.code, flag.name)}>
                                        {t('admin.issues.actions.override', { rule: flag.name })}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </td>
                </tr>
            ))}
        </AdminTable>
    );
}
