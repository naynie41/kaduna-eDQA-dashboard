import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList, type Translate } from '@/lib/i18n';
import type { ShellStatus, SyncState, SyncStatus } from '@/types/shell';

export type SyncStatusBarProps = {
    status: ShellStatus;
};

const TONE: Record<SyncState, string> = {
    not_configured: 'border-rule bg-paper text-ink-2',
    disabled: 'border-rule bg-paper text-ink-2',
    not_connected: 'border-rule bg-paper text-ink-2',
    healthy: 'border-ok-bg bg-ok-bg text-ok',
    stale: 'border-warn-bg bg-warn-bg text-warn',
    failed: 'border-bad-bg bg-bad-bg text-bad',
    auth_failed: 'border-bad-bg bg-bad-bg text-bad',
};

// A shape per state as well as a colour and the words (CLAUDE.md hard rule 11); decorative.
const MARK: Record<SyncState, string> = { not_configured: '○', disabled: '○', not_connected: '○', healthy: '●', stale: '▲', failed: '■', auth_failed: '■' };

/**
 * The sources' sync line above the top bar (ARCHITECTURE.md §9.1): every source by name, worst
 * state first (the server orders them), each said in words with its own mark (ACCESSIBILITY.md
 * §6), then the counts. The bar takes the worst source's tone. Not configured, switched off and
 * waiting for the first pull are neutral: nothing has failed.
 */
export default function SyncStatusBar({ status }: SyncStatusBarProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const at = (value: string | null) => (value === null || months.length !== 12 ? '' : formatDateTime(value, months));
    const worst = status.sources[0]?.state ?? 'not_configured';
    const counts = t('common.sync.counts', { assessments: formatCount(status.assessments), quarantined: formatCount(status.quarantined) });

    return (
        <div role="status" aria-label={t('common.sync.label')} className={`flex flex-wrap items-center gap-x-3 gap-y-0.5 border-b px-4 py-1.5 text-[12.5px] rail:px-6 ${TONE[worst]}`}>
            {status.sources.length === 0 ? (
                <span>{t('common.sync.none')}</span>
            ) : (
                status.sources.map((source) => (
                    <span key={source.key} className="inline-flex items-center gap-1.5">
                        <span aria-hidden="true" className="text-[10px]">
                            {MARK[source.state]}
                        </span>
                        <span>{sentence(t, source, at)} ·</span>
                    </span>
                ))
            )}
            <span>{counts}</span>
        </div>
    );
}

function sentence(t: Translate, source: SyncStatus, at: (value: string | null) => string): string {
    const name = { source: source.name };
    const minutes = source.minutesSinceSuccess === null ? '' : formatCount(source.minutesSinceSuccess);
    const success = source.lastSuccessAt === null ? t('common.sync.never_succeeded') : at(source.lastSuccessAt);

    switch (source.state) {
        case 'not_configured':
        case 'disabled':
        case 'not_connected':
            return t(`common.sync.${source.state}`, name);
        case 'healthy':
            return t('common.sync.healthy', { ...name, minutes });
        case 'stale':
            return t('common.sync.stale', { ...name, minutes, limit: source.staleAfterMinutes });
        case 'auth_failed':
            return t('common.sync.auth_failed', { ...name, time: at(source.lastFailedAt), success });
        case 'failed':
            return source.lastErrorClass === null
                ? t('common.sync.failed_no_error', { ...name, time: at(source.lastFailedAt), success })
                : t('common.sync.failed', { ...name, time: at(source.lastFailedAt), error: source.lastErrorClass, success });
    }
}
