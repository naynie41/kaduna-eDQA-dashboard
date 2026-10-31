import { useTranslate } from '@/lib/i18n';
import type { DataIssueRow } from '@/types/admin';

/**
 * Both sides of a DUPLICATE_ASSESSMENT, each by its source: the assessment already accepted (with
 * a link to it on its source, when its link settings allow) and this submission.
 */
export default function DuplicateSides({ row }: { row: DataIssueRow }) {
    const t = useTranslate();
    const original = row.duplicate_of;
    if (original === null) {
        return null;
    }
    const unknown = t('admin.issues.table.source_unknown');

    return (
        <dl aria-label={t('admin.issues.duplicate.label')} className="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-md border border-rule bg-paper px-2.5 py-2 text-[12.5px]">
            <dt className="text-ink-2">{t('admin.issues.duplicate.accepted')}</dt>
            <dd>
                {t('admin.issues.duplicate.side', { source: original.source?.name ?? unknown })} <code className="text-[12px] break-all">{original.instance_id}</code>
                {original.source !== null && original.url !== null && (
                    <a href={original.url} target="_blank" rel="noopener noreferrer" className="ml-1 text-deep underline">
                        {t('admin.issues.actions.open_in_source', { source: original.source.name })} <span className="sr-only">{t('admin.issues.actions.new_tab')}</span>
                    </a>
                )}
            </dd>
            <dt className="text-ink-2">{t('admin.issues.duplicate.this')}</dt>
            <dd>
                {t('admin.issues.duplicate.side', { source: row.source?.name ?? unknown })} <code className="text-[12px] break-all">{row.instance_id}</code>
            </dd>
        </dl>
    );
}
