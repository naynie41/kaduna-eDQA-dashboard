import type { ReactNode } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import SourceRunControl, { type RunCounter } from '@/components/SourceRunControl';
import StateMark from '@/components/StateMark';
import { formatCount, formatDateTime, formatTime } from '@/lib/format';
import { useTranslate, useTranslationList, type Translate } from '@/lib/i18n';
import type { SourceEntry } from '@/types/admin';
import type { SyncStatus } from '@/types/shell';

export type FollowedRun = { id: number; attached: boolean };

export type SourcePanelProps = {
    source: SourceEntry;
    /** The credentials-day runbook's link, or null to name the file instead. */
    runbookUrl: string | null;
    /** The pull and the reconcile this panel follows (owned by the page, so Pull all can start one). */
    pull: FollowedRun | null;
    reconcile: FollowedRun | null;
    onPull: (run: FollowedRun) => void;
    onReconcile: (run: FollowedRun) => void;
    onFinished: () => void;
};

const PULL_COUNTERS: readonly RunCounter[] = ['fetched', 'parsed', 'accepted', 'flagged', 'quarantined', 'duplicates', 'cross_source_duplicates'];
const RECONCILE_COUNTERS: readonly RunCounter[] = ['fetched', 'parsed', 'accepted', 'flagged', 'quarantined', 'deletions_detected'];

/**
 * One source on the Data sources page (ARCHITECTURE.md §9.3), named by the source: configured
 * and enabled, reachable, the last success (how long ago, and when in WAT), what the last pull
 * fetched, this source's counts in each open round so far, where its cursor stands, the last
 * error in plain language ("KoboToolbox returned HTTP 503 at 14:02 WAT; last success 13:40
 * WAT"), and Pull now and Reconcile, each followed in its own ProgressPanel. Every state is said
 * in words, with a shape as well as a colour (CLAUDE.md hard rule 11).
 */
export default function SourcePanel({ source, runbookUrl, pull, reconcile, onPull, onReconcile, onFinished }: SourcePanelProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);
    const { status: sync, reachable, cursor, last_run: lastRun } = source;
    const titleId = `source-${source.key}-title`;
    const name = { source: source.name };

    if (!source.configured) {
        return (
            <Panel labelledBy={titleId}>
                <PanelHeader id={titleId} title={t('admin.sources.panel.title', name)} />
                <div className="flex flex-col gap-2 px-5 pb-5 text-sm">
                    <State mark="○" tone="text-ink-2" label={t('admin.sources.panel.not_configured')} />
                    <p>{t('admin.sources.panel.not_configured_body', name)}</p>
                    <ul className="list-disc pl-5">
                        {source.problems.map((problem) => (
                            <li key={problem}>
                                <code className="rounded bg-paper px-1 py-px text-xs">{problem}</code>
                            </li>
                        ))}
                    </ul>
                    <p>
                        {runbookUrl === null ? (
                            t('admin.sources.panel.runbook_file')
                        ) : (
                            <a href={runbookUrl} className="font-medium text-deep underline" target="_blank" rel="noreferrer">
                                {t('admin.sources.panel.runbook')}
                            </a>
                        )}
                    </p>
                </div>
            </Panel>
        );
    }

    const lastSuccess =
        sync.lastSuccessAt === null || sync.sinceSuccess === null
            ? t('admin.sources.panel.never')
            : t('admin.sources.panel.last_success_value', {
                  ago: t(`admin.sources.panel.ago.${sync.sinceSuccess.unit}`, { count: formatCount(sync.sinceSuccess.value) }),
                  time: when(sync.lastSuccessAt),
              });

    return (
        <Panel labelledBy={titleId}>
            <PanelHeader id={titleId} title={t('admin.sources.panel.title', name)} />
            <div className="flex flex-col gap-3 px-5 pb-5 text-sm">
                {source.enabled && sync.state in STATES && (
                    <div>
                        <State mark={STATES[sync.state as RunState].mark} tone={STATES[sync.state as RunState].tone} label={t(`admin.sources.panel.state.${sync.state}`)} />
                        <p className="mt-0.5">{stateBody(t, source.name, sync)}</p>
                    </div>
                )}
                {!source.enabled ? (
                    <div>
                        <State mark="○" tone="text-ink-2" label={t('admin.sources.panel.disabled')} />
                        <p className="mt-0.5">{t('admin.sources.panel.disabled_body', name)}</p>
                    </div>
                ) : reachable === null || reachable.ok ? (
                    <div>
                        <State mark="●" tone="text-ok" label={t('admin.sources.panel.reachable')} />
                        {reachable !== null && <p className="mt-0.5 text-ink-2">{t('admin.sources.panel.reachable_at', { ...name, time: formatTime(reachable.checked_at) })}</p>}
                    </div>
                ) : (
                    <div>
                        <State mark="■" tone="text-bad" label={t('admin.sources.panel.unreachable')} />
                        <p className="mt-0.5">{t('admin.sources.panel.unreachable_body', { ...name, time: formatTime(reachable.checked_at), message: reachable.message ?? reachable.status })}</p>
                    </div>
                )}

                {(sync.state === 'failed' || sync.state === 'auth_failed') && sync.lastFailedAt !== null && (
                    <div className="notice notice-bad text-bad">
                        <p className="font-medium">{t('admin.sources.panel.last_error')}</p>
                        <p className="text-ink">
                            {errorSentence(t, source, sync, formatTime(sync.lastFailedAt), sync.lastSuccessAt === null ? t('admin.sources.panel.never_inline') : formatTime(sync.lastSuccessAt))}
                        </p>
                        {sync.lastError !== null && <p className="mt-0.5 text-xs break-words text-ink">{t('admin.sources.panel.error_detail', { error: sync.lastError })}</p>}
                    </div>
                )}

                <dl className="grid gap-x-6 gap-y-2.5 rail:grid-cols-[minmax(12rem,auto)_1fr]">
                    <Term label={t('admin.sources.panel.last_success')}>{lastSuccess}</Term>
                    <Term label={t('admin.sources.panel.last_pull')}>
                        {lastRun === null
                            ? t('admin.sources.panel.no_pull')
                            : t('admin.sources.panel.last_pull_value', {
                                  fetched: formatCount(lastRun.fetched),
                                  accepted: formatCount(lastRun.accepted),
                                  flagged: formatCount(lastRun.flagged),
                                  quarantined: formatCount(lastRun.quarantined),
                                  duplicates: formatCount(lastRun.duplicates),
                                  cross: formatCount(lastRun.cross_source_duplicates),
                              })}
                    </Term>
                    {source.open_rounds.length === 0 ? (
                        <Term label={t('admin.sources.panel.open_rounds')}>{t('admin.sources.panel.no_open_round')}</Term>
                    ) : (
                        source.open_rounds.map((round) => (
                            <Term key={round.id} label={t('admin.sources.panel.open_round', { quarter: round.quarter, year: round.year })}>
                                {t('admin.sources.panel.open_round_value', {
                                    accepted: formatCount(round.accepted),
                                    flagged: formatCount(round.flagged),
                                    quarantined: formatCount(round.quarantined),
                                })}
                            </Term>
                        ))
                    )}
                    <Term label={t('admin.sources.panel.cursor')}>
                        {cursor === null
                            ? t('admin.sources.panel.cursor_none')
                            : t('admin.sources.panel.cursor_value', {
                                  pulled: cursor.last_pulled_at === null ? t('admin.sources.panel.not_yet') : when(cursor.last_pulled_at),
                                  edited: cursor.last_edit_at === null ? t('admin.sources.panel.not_yet') : when(cursor.last_edit_at),
                                  count: formatCount(cursor.submission_count),
                              })}
                    </Term>
                    <Term label={t('admin.sources.panel.reconciled')}>{source.last_reconciled_at === null ? t('admin.sources.panel.reconciled_never') : when(source.last_reconciled_at)}</Term>
                </dl>

                {source.enabled && (
                    <div className="flex flex-col gap-4 border-t border-rule pt-3">
                        <SourceRunControl
                            url={`/api/sources/${source.key}/pull`}
                            runId={pull?.id ?? null}
                            attached={pull?.attached ?? false}
                            counters={PULL_COUNTERS}
                            labels={{
                                button: t('admin.sources.pull.button'),
                                source: source.name,
                                starting: t('admin.sources.pull.starting'),
                                attached: t('admin.sources.pull.attached', name),
                                progress: t('admin.sources.pull.progress', name),
                            }}
                            onStarted={(id, attached) => onPull({ id, attached })}
                            onFinished={onFinished}
                        />
                        <div className="flex flex-col gap-2">
                            <p className="text-sm text-ink-2">{t('admin.sources.reconcile.description')}</p>
                            <SourceRunControl
                                url={`/api/sources/${source.key}/reconcile`}
                                runId={reconcile?.id ?? null}
                                attached={reconcile?.attached ?? false}
                                counters={RECONCILE_COUNTERS}
                                labels={{
                                    button: t('admin.sources.reconcile.button'),
                                    source: source.name,
                                    starting: t('admin.sources.reconcile.starting'),
                                    attached: t('admin.sources.reconcile.attached', name),
                                    progress: t('admin.sources.reconcile.progress', name),
                                }}
                                tone="secondary"
                                onStarted={(id, attached) => onReconcile({ id, attached })}
                                onFinished={onFinished}
                            />
                        </div>
                    </div>
                )}
            </div>
        </Panel>
    );
}

type RunState = 'healthy' | 'stale' | 'failed' | 'auth_failed' | 'not_connected';

// The headline state of a configured, switched-on source: a shape and a tone, always with words.
const STATES: Record<RunState, { mark: string; tone: string }> = {
    healthy: { mark: '●', tone: 'text-ok' },
    stale: { mark: '▲', tone: 'text-warn' },
    failed: { mark: '■', tone: 'text-bad' },
    auth_failed: { mark: '■', tone: 'text-bad' },
    not_connected: { mark: '○', tone: 'text-ink-2' },
};

function stateBody(t: Translate, source: string, sync: SyncStatus): string {
    switch (sync.state) {
        case 'healthy':
            return sync.sinceSuccess === null
                ? t('admin.sources.panel.state_body.not_connected')
                : t('admin.sources.panel.state_body.healthy', { ago: t(`admin.sources.panel.ago.${sync.sinceSuccess.unit}`, { count: formatCount(sync.sinceSuccess.value) }) });
        case 'stale':
            return t('admin.sources.panel.state_body.stale', { minutes: formatCount(sync.staleAfterMinutes) });
        default:
            return t(`admin.sources.panel.state_body.${sync.state}`, { source });
    }
}

/**
 * "KoboToolbox returned HTTP 401 at 14:02 WAT — the API token was rejected. Every scheduled pull
 * from KoboToolbox will fail the same way until the setting is fixed on the server. Last success:
 * never." What the status means for this kind of source, and what happens next: the schedule keeps
 * trying, so a settings problem (401, 403, 404) fails every time, and anything else may pass next
 * time. Without a status, the error class in words: OdkAuthFailed and KoboAuthFailed are both
 * "refused the portal's credentials".
 */
function errorSentence(t: Translate, source: SourceEntry, sync: SyncStatus, time: string, success: string): string {
    const status = sync.lastErrorHttpStatus;
    if (status !== null) {
        const meaning =
            status === 401
                ? source.type === 'kobo'
                    ? 'unauthorized_kobo'
                    : 'unauthorized_odk'
                : status === 403
                  ? 'forbidden'
                  : status === 404
                    ? 'not_found'
                    : status === 429
                      ? 'too_many'
                      : status >= 500
                        ? 'server'
                        : 'other';
        const settings = status === 401 || status === 403 || status === 404;

        return [
            t('admin.sources.panel.error_status', { source: source.name, status, time, meaning: t(`admin.sources.panel.meaning.${meaning}`) }),
            t(`admin.sources.panel.consequence.${settings ? 'settings' : 'retry'}`, { source: source.name }),
            t('admin.sources.panel.last_success_sentence', { success }),
        ].join(' ');
    }
    const kind = errorKind(sync.lastErrorClass);

    return t('admin.sources.panel.error_class', { what: t(`admin.sources.panel.errors.${kind}`, { source: source.name }), time, success });
}

function errorKind(errorClass: string | null): 'auth' | 'unreachable' | 'unexpected' | 'QueryException' | 'other' {
    if (errorClass === null) {
        return 'other';
    }
    if (errorClass === 'QueryException') {
        return 'QueryException';
    }
    if (errorClass.endsWith('AuthFailed')) {
        return 'auth';
    }
    if (errorClass.endsWith('Unreachable')) {
        return 'unreachable';
    }

    return errorClass.endsWith('UnexpectedResponse') ? 'unexpected' : 'other';
}

function State({ mark, tone, label }: { mark: string; tone: string; label: string }) {
    return (
        <p className={`flex items-center gap-2 font-semibold ${tone}`}>
            <StateMark mark={mark} />
            {label}
        </p>
    );
}

function Term({ label, children }: { label: string; children: ReactNode }) {
    return (
        <>
            <dt className="text-xs text-ink-2">{label}</dt>
            <dd>{children}</dd>
        </>
    );
}
