import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { ShellStatus, SyncState } from '@/types/shell';

export type SyncStatusBarProps = {
    status: ShellStatus;
};

const TONE: Record<SyncState, string> = {
    not_configured: 'border-rule bg-paper text-ink-2',
    not_connected: 'border-rule bg-paper text-ink-2',
    ok: 'border-ok-bg bg-ok-bg text-ok',
    stale: 'border-warn-bg bg-warn-bg text-warn',
    failed: 'border-bad-bg bg-bad-bg text-bad',
};

// A shape per state as well as a colour and the words (CLAUDE.md hard rule 11); decorative.
const MARK: Record<SyncState, string> = { not_configured: '○', not_connected: '○', ok: '●', stale: '▲', failed: '■' };

/**
 * The ODK sync line above the top bar (ARCHITECTURE.md §9.1, D-54). The words always state the
 * condition: not configured and waiting for the first pull in the neutral style (nothing has
 * failed); healthy; stale past the limit; failed with when, the error's class and the last success.
 */
export default function SyncStatusBar({ status }: SyncStatusBarProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const { sync } = status;
    const counts = { assessments: formatCount(status.assessments), quarantined: formatCount(status.quarantined) };
    const minutes = sync.minutesSinceSuccess === null ? '' : formatCount(sync.minutesSinceSuccess);

    const message = (() => {
        switch (sync.state) {
            case 'not_configured':
                return t('common.sync.not_configured');
            case 'not_connected':
                return t('common.sync.not_connected');
            case 'ok':
                return t('common.sync.ok', { minutes, ...counts });
            case 'stale':
                return t('common.sync.stale', { minutes, limit: sync.staleAfterMinutes, ...counts });
            case 'failed': {
                const at = (value: string | null) => (value === null || months.length !== 12 ? '' : formatDateTime(value, months));
                const time = at(sync.lastFailedAt);
                const success = sync.lastSuccessAt === null ? t('common.sync.never_succeeded') : at(sync.lastSuccessAt);

                return sync.lastErrorClass === null
                    ? t('common.sync.failed_no_error', { time, success, ...counts })
                    : t('common.sync.failed', { time, error: sync.lastErrorClass, success, ...counts });
            }
        }
    })();

    return (
        <div role="status" className={`flex items-center gap-2 border-b px-4 py-1.5 text-[12.5px] rail:px-6 ${TONE[sync.state]}`}>
            <span aria-hidden="true" className="text-[10px]">
                {MARK[sync.state]}
            </span>
            <span>{message}</span>
        </div>
    );
}
