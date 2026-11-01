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
 * from the console on purpose, one source at a time. For each configured source, its last run and
 * the checkpoint it resumes from; then a signed link (30 minutes) to the newest backfill report,
 * which covers every source.
 */
export default function BackfillPanel({ backfill }: BackfillPanelProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);
    const { sources, report } = backfill;

    return (
        <Panel labelledBy="sources-backfill-title">
            <PanelHeader id="sources-backfill-title" title={t('admin.sources.backfill.title')} description={t('admin.sources.backfill.description')} />
            <div className="flex flex-col gap-4 px-5 pt-1 pb-5 text-sm">
                {sources.map(({ key, name, run, checkpoint }) => (
                    <div key={key} role="group" aria-labelledby={`backfill-${key}-title`} className="border-t border-rule pt-3 first:border-t-0 first:pt-0">
                        <h3 id={`backfill-${key}-title`} className="mb-2 text-base font-semibold">
                            {name}
                        </h3>
                        <dl className="grid gap-x-6 gap-y-2 rail:grid-cols-[minmax(12rem,auto)_1fr]">
                            <dt className="text-xs text-ink-2">{t('admin.sources.backfill.last_run')}</dt>
                            <dd>
                                {run === null
                                    ? t('admin.sources.backfill.none')
                                    : t('admin.sources.backfill.run_value', {
                                          started: when(run.started_at),
                                          outcome: t(`admin.sources.backfill.outcomes.${run.outcome ?? 'running'}`),
                                          fetched: formatCount(run.fetched),
                                          accepted: formatCount(run.accepted),
                                          flagged: formatCount(run.flagged),
                                          quarantined: formatCount(run.quarantined),
                                          duplicates: formatCount(run.duplicates),
                                      })}
                            </dd>
                            <dt className="text-xs text-ink-2">{t('admin.sources.backfill.checkpoint')}</dt>
                            <dd>
                                {t('admin.sources.backfill.checkpoint_value', {
                                    position: formatCount(checkpoint.position),
                                    page: formatCount(checkpoint.page),
                                    size: formatCount(checkpoint.page_size),
                                })}
                            </dd>
                        </dl>
                    </div>
                ))}
                <dl className="grid gap-x-6 gap-y-2 border-t border-rule pt-3 rail:grid-cols-[minmax(12rem,auto)_1fr]">
                    <dt className="text-xs text-ink-2">{t('admin.sources.backfill.report')}</dt>
                    <dd>
                        {report === null ? (
                            t('admin.sources.backfill.report_none')
                        ) : (
                            <>
                                <a href={report.url} className="font-medium text-deep underline">
                                    {t('admin.sources.backfill.report_link', { time: when(report.at) })}
                                </a>
                                <span className="block text-xs text-ink-2">{t('admin.sources.backfill.report_expiry')}</span>
                            </>
                        )}
                    </dd>
                </dl>
            </div>
        </Panel>
    );
}
