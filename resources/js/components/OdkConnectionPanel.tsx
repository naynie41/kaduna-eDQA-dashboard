import type { ReactNode } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount, formatDateTime, formatTime } from '@/lib/format';
import { useTranslate, useTranslationList, type Translate } from '@/lib/i18n';
import type { OdkStatus } from '@/types/admin';

export type OdkConnectionPanelProps = {
    status: OdkStatus;
    /** The credentials-day runbook's link, or null to name the file instead. */
    runbookUrl: string | null;
};

const ERROR_CLASSES = ['OdkUnreachable', 'OdkAuthFailed', 'OdkUnexpectedResponse', 'QueryException'];

/**
 * The ODK pull page's connection panel (ARCHITECTURE.md §9.3, D-56), from GET /api/odk/status.
 * Every state is said in words, with a shape as well as a colour: not configured (what to set,
 * never the values, and the runbook), connected or not reachable, the last success (how long
 * ago, and when in WAT), the last pull's submissions, each open round so far, where the cursor
 * stands, and the last error in plain language ("ODK Central returned HTTP 503 at 14:02 WAT;
 * last success 13:40 WAT").
 */
export default function OdkConnectionPanel({ status, runbookUrl }: OdkConnectionPanelProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);
    const { sync, reachable, cursor, last_run: lastRun } = status;

    if (!status.configured) {
        return (
            <Panel labelledBy="odk-connection-title">
                <PanelHeader id="odk-connection-title" title={t('admin.odk.connection.title')} />
                <div className="flex flex-col gap-2 px-5 pb-5 text-[13.5px]">
                    <State mark="○" tone="text-ink-2" label={t('admin.odk.connection.not_configured')} />
                    <p>{t('admin.odk.connection.not_configured_body')}</p>
                    <ul className="list-disc pl-5">
                        {status.problems.map((problem) => (
                            <li key={problem}>
                                <code className="rounded bg-paper px-1 py-px text-[12.5px]">{problem}</code>
                            </li>
                        ))}
                    </ul>
                    <p>
                        {runbookUrl === null ? (
                            t('admin.odk.connection.runbook_file')
                        ) : (
                            <a href={runbookUrl} className="font-medium text-deep underline" target="_blank" rel="noreferrer">
                                {t('admin.odk.connection.runbook')}
                            </a>
                        )}
                    </p>
                </div>
            </Panel>
        );
    }

    const lastSuccess =
        sync.lastSuccessAt === null || sync.sinceSuccess === null
            ? t('admin.odk.connection.never')
            : t('admin.odk.connection.last_success_value', {
                  ago: t(`admin.odk.connection.ago.${sync.sinceSuccess.unit}`, { count: formatCount(sync.sinceSuccess.value) }),
                  time: when(sync.lastSuccessAt),
              });

    return (
        <Panel labelledBy="odk-connection-title">
            <PanelHeader id="odk-connection-title" title={t('admin.odk.connection.title')} />
            <div className="flex flex-col gap-3 px-5 pb-5 text-[13.5px]">
                {reachable === null || reachable.ok ? (
                    <div>
                        <State mark="●" tone="text-ok" label={t('admin.odk.connection.reachable')} />
                        {reachable !== null && <p className="mt-0.5 text-ink-2">{t('admin.odk.connection.reachable_at', { time: formatTime(reachable.checked_at) })}</p>}
                    </div>
                ) : (
                    <div>
                        <State mark="■" tone="text-bad" label={t('admin.odk.connection.unreachable')} />
                        <p className="mt-0.5">{t('admin.odk.connection.unreachable_body', { time: formatTime(reachable.checked_at), message: reachable.message ?? reachable.status })}</p>
                    </div>
                )}

                {sync.state === 'failed' && sync.lastFailedAt !== null && (
                    <div className="rounded-md border border-bad bg-bad-bg px-3 py-2 text-bad">
                        <p className="font-medium">{t('admin.odk.connection.last_error')}</p>
                        <p className="text-ink">{errorSentence(t, sync, formatTime(sync.lastFailedAt), sync.lastSuccessAt === null ? t('admin.odk.connection.never_inline') : formatTime(sync.lastSuccessAt))}</p>
                        {sync.lastError !== null && <p className="mt-0.5 break-words text-[12.5px] text-ink-2">{t('admin.odk.connection.error_detail', { error: sync.lastError })}</p>}
                    </div>
                )}

                <dl className="grid gap-x-6 gap-y-2.5 rail:grid-cols-[minmax(12rem,auto)_1fr]">
                    <Term label={t('admin.odk.connection.last_success')}>{lastSuccess}</Term>
                    <Term label={t('admin.odk.connection.last_pull')}>
                        {lastRun === null
                            ? t('admin.odk.connection.no_pull')
                            : t('admin.odk.connection.last_pull_value', {
                                  fetched: formatCount(lastRun.fetched),
                                  accepted: formatCount(lastRun.accepted),
                                  flagged: formatCount(lastRun.flagged),
                                  quarantined: formatCount(lastRun.quarantined),
                                  duplicates: formatCount(lastRun.duplicates),
                              })}
                    </Term>
                    {status.open_rounds.length === 0 ? (
                        <Term label={t('admin.odk.connection.open_rounds')}>{t('admin.odk.connection.no_open_round')}</Term>
                    ) : (
                        status.open_rounds.map((round) => (
                            <Term key={round.id} label={t('admin.odk.connection.open_round', { quarter: round.quarter, year: round.year })}>
                                {t('admin.odk.connection.open_round_value', {
                                    accepted: formatCount(round.accepted),
                                    flagged: formatCount(round.flagged),
                                    quarantined: formatCount(round.quarantined),
                                })}
                            </Term>
                        ))
                    )}
                    <Term label={t('admin.odk.connection.cursor')}>
                        {cursor === null
                            ? t('admin.odk.connection.cursor_none')
                            : t('admin.odk.connection.cursor_value', {
                                  pulled: cursor.last_pulled_at === null ? t('admin.odk.connection.not_yet') : when(cursor.last_pulled_at),
                                  edited: cursor.last_edit_at === null ? t('admin.odk.connection.not_yet') : when(cursor.last_edit_at),
                                  count: formatCount(cursor.submission_count),
                              })}
                    </Term>
                </dl>
            </div>
        </Panel>
    );
}

/** "ODK Central returned HTTP 503 at 14:02 WAT; last success 13:40 WAT", or the class in words. */
function errorSentence(t: Translate, sync: OdkStatus['sync'], time: string, success: string): string {
    if (sync.lastErrorHttpStatus !== null) {
        return t('admin.odk.connection.error_status', { who: t('admin.odk.connection.who'), status: sync.lastErrorHttpStatus, time, success });
    }
    const known = sync.lastErrorClass !== null && ERROR_CLASSES.includes(sync.lastErrorClass);

    return t('admin.odk.connection.error_class', { what: t(`admin.odk.connection.errors.${known ? sync.lastErrorClass : 'other'}`), time, success });
}

function State({ mark, tone, label }: { mark: string; tone: string; label: string }) {
    return (
        <p className={`flex items-center gap-2 font-semibold ${tone}`}>
            <span aria-hidden="true" className="text-[11px]">
                {mark}
            </span>
            {label}
        </p>
    );
}

function Term({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-[12.5px] text-ink-2">{label}</dt>
            <dd>{children}</dd>
        </>
    );
}
