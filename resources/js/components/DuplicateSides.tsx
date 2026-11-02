import type { ReactNode } from 'react';
import ScoreTag from '@/components/ScoreTag';
import SourceTag from '@/components/SourceTag';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { DataIssueRow } from '@/types/admin';

/**
 * Both sides of a DUPLICATE_ASSESSMENT (ARCHITECTURE.md §5.8), side by side for the administrator
 * deciding which one counts: each submission's source (the accepted one linked on its source,
 * when its link settings allow), instance ID, when it was submitted, who assessed, and the overall
 * each scores under the current rule version. A table, so a screen reader hears each value with
 * its row and column.
 */
export default function DuplicateSides({ row }: { row: DataIssueRow }) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const original = row.duplicate_of;
    if (original === null) {
        return null;
    }
    const when = (value: string | null | undefined) => (value === null || value === undefined ? t('admin.issues.duplicate.unknown') : months.length === 12 ? formatDateTime(value, months) : value);
    const known = (value: string | null | undefined) => value ?? t('admin.issues.duplicate.unknown');
    const mine = original.this;

    return (
        <table className="mt-1.5 w-full max-w-md border-collapse rounded-md border border-rule bg-paper text-xs">
            <caption className="px-2.5 pt-2 pb-1 text-left font-medium text-ink">{t('admin.issues.duplicate.label')}</caption>
            <thead>
                <tr className="border-b border-rule text-left text-2xs text-ink-2">
                    <td className="px-2.5 py-1" />
                    <th scope="col" className="px-2.5 py-1 font-semibold">
                        {t('admin.issues.duplicate.accepted')}
                    </th>
                    <th scope="col" className="px-2.5 py-1 font-semibold">
                        {t('admin.issues.duplicate.this')}
                    </th>
                </tr>
            </thead>
            <tbody className="align-top">
                <Line label={t('admin.issues.duplicate.source')}>
                    <>
                        <SourceTag source={original.source} />
                        {original.source !== null && original.url !== null && (
                            <a href={original.url} target="_blank" rel="noopener noreferrer" className="mt-0.5 block text-deep underline">
                                {t('admin.issues.actions.open_in_source', { source: original.source.name })} <span className="sr-only">{t('admin.issues.actions.new_tab')}</span>
                            </a>
                        )}
                    </>
                    <SourceTag source={row.source ?? null} />
                </Line>
                <Line label={t('admin.issues.duplicate.instance')}>
                    <code className="break-all">{original.instance_id}</code>
                    <code className="break-all">{row.instance_id}</code>
                </Line>
                <Line label={t('admin.issues.duplicate.submitted')}>
                    <>{when(original.submitted_at)}</>
                    <>{when(mine?.submitted_at)}</>
                </Line>
                <Line label={t('admin.issues.duplicate.assessor')}>
                    <>{known(original.assessor)}</>
                    <>{known(mine?.assessor)}</>
                </Line>
                <Line label={t('admin.issues.duplicate.overall')}>
                    {original.overall ? <ScoreTag score={original.overall} /> : <>{t('admin.issues.duplicate.unknown')}</>}
                    {mine?.overall ? <ScoreTag score={mine.overall} /> : <>{t('admin.issues.duplicate.unknown')}</>}
                </Line>
            </tbody>
        </table>
    );
}

function Line({ label, children }: { label: string; children: [ReactNode, ReactNode] }) {
    return (
        <tr className="border-b border-rule last:border-b-0">
            <th scope="row" className="px-2.5 py-1 text-left font-normal whitespace-nowrap text-ink-2">
                {label}
            </th>
            <td className="px-2.5 py-1">{children[0]}</td>
            <td className="px-2.5 py-1">{children[1]}</td>
        </tr>
    );
}
