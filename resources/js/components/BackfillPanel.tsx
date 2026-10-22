import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { BackfillStatus } from '@/types/admin';

export type BackfillPanelProps = {
    backfill: BackfillStatus;
};

/**
 * The historical backfill, read-only (ARCHITECTURE.md §5.6, D-53, D-56): a backfill is started
 * from the console on purpose. The last run, the checkpoint it resumes from, and a signed link
 * (30 minutes) to the newest backfill report.
 */
export default function BackfillPanel({ backfill }: BackfillPanelProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);
    const { run, checkpoint, report } = backfill;

    return (
        <Panel labelledBy="odk-backfill-title">
            <PanelHeader id="odk-backfill-title" title={t('admin.odk.backfill.title')} description={t('admin.odk.backfill.description')} />
            <dl className="grid gap-x-6 gap-y-2.5 px-5 pb-5 text-[13.5px] rail:grid-cols-[minmax(12rem,auto)_1fr]">
                <dt className="text-[12.5px] text-ink-2">{t('admin.odk.backfill.last_run')}</dt>
                <dd>
                    {run === null
                        ? t('admin.odk.backfill.none')
                        : t('admin.odk.backfill.run_value', {
                              started: when(run.started_at),
                              outcome: t(`admin.odk.backfill.outcomes.${run.outcome ?? 'running'}`),
                              fetched: formatCount(run.fetched),
                              accepted: formatCount(run.accepted),
                              flagged: formatCount(run.flagged),
                              quarantined: formatCount(run.quarantined),
                              duplicates: formatCount(run.duplicates),
                          })}
                </dd>
                <dt className="text-[12.5px] text-ink-2">{t('admin.odk.backfill.checkpoint')}</dt>
                <dd>
                    {t('admin.odk.backfill.checkpoint_value', {
                        position: formatCount(checkpoint.position),
                        page: formatCount(checkpoint.page),
                        size: formatCount(checkpoint.page_size),
                    })}
                </dd>
                <dt className="text-[12.5px] text-ink-2">{t('admin.odk.backfill.report')}</dt>
                <dd>
                    {report === null ? (
                        t('admin.odk.backfill.report_none')
                    ) : (
                        <>
                            <a href={report.url} className="font-medium text-deep underline">
                                {t('admin.odk.backfill.report_link', { time: when(report.at) })}
                            </a>
                            <span className="block text-[12.5px] text-ink-2">{t('admin.odk.backfill.report_expiry')}</span>
                        </>
                    )}
                </dd>
            </dl>
        </Panel>
    );
}
