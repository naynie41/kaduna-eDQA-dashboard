import { router } from '@inertiajs/react';
import { useId } from 'react';
import AdminTable from '@/components/AdminTable';
import { useAnnounce } from '@/components/Announcer';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { Paginated, PullHistoryRow, PullOutcomeValue } from '@/types/admin';

export type PullHistoryFilters = { trigger: string | null; outcome: string | null };

export type PullHistoryPanelProps = {
    history: Paginated<PullHistoryRow>;
    filters: PullHistoryFilters;
};

const TRIGGERS = ['scheduled', 'manual', 'webhook', 'backfill'] as const;
const OUTCOMES: PullOutcomeValue[] = ['succeeded', 'failed', 'skipped_not_configured', 'running'];

// An outcome in words, with a shape as well as a colour (CLAUDE.md hard rule 11).
const OUTCOME_STYLE: Record<PullOutcomeValue, { tone: string; mark: string }> = {
    succeeded: { tone: 'bg-ok-bg text-ok', mark: '●' },
    failed: { tone: 'bg-bad-bg text-bad', mark: '■' },
    skipped_not_configured: { tone: 'bg-paper text-ink-2', mark: '○' },
    running: { tone: 'bg-wash text-deep', mark: '◐' },
};

/**
 * The pull history (ARCHITECTURE.md §9.3, D-56): newest first, 25 a page from the server,
 * filtered by trigger and outcome. A filter or page change reloads only the history and says
 * how many pulls are shown.
 */
export default function PullHistoryPanel({ history, filters }: PullHistoryPanelProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const ids = { trigger: useId(), outcome: useId() };

    const visit = (next: Partial<PullHistoryFilters> & { page?: number }) => {
        const query = { ...filters, ...next };
        router.get(
            '/admin/odk',
            Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '')),
            {
                only: ['history', 'historyFilters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: (page) => {
                    const total = (page.props.history as Paginated<PullHistoryRow> | undefined)?.total ?? 0;
                    announce(t('admin.odk.history.filtered', { total: formatCount(total) }));
                },
            },
        );
    };

    const columns = [
        { key: 'started', header: t('admin.odk.history.started') },
        { key: 'trigger', header: t('admin.odk.history.trigger') },
        { key: 'by', header: t('admin.odk.history.by') },
        { key: 'retrieved', header: t('admin.odk.history.retrieved'), align: 'end' as const },
        { key: 'accepted', header: t('admin.odk.history.accepted'), align: 'end' as const },
        { key: 'flagged', header: t('admin.odk.history.flagged'), align: 'end' as const },
        { key: 'quarantined', header: t('admin.odk.history.quarantined'), align: 'end' as const },
        { key: 'duration', header: t('admin.odk.history.duration'), align: 'end' as const },
        { key: 'outcome', header: t('admin.odk.history.outcome') },
    ];

    const select = 'min-h-11 rounded-md border border-rule bg-panel px-2.5 text-[13.5px]';

    return (
        <Panel labelledBy="odk-history-title">
            <PanelHeader id="odk-history-title" title={t('admin.odk.history.title')} />
            <div className="flex flex-wrap gap-3 px-5 pb-3">
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.trigger} className="text-[12.5px] text-ink-2">
                        {t('admin.odk.history.filter_trigger')}
                    </label>
                    <select id={ids.trigger} className={select} value={filters.trigger ?? ''} onChange={(event) => visit({ trigger: event.target.value || null, page: 1 })}>
                        <option value="">{t('admin.odk.history.all_triggers')}</option>
                        {TRIGGERS.map((trigger) => (
                            <option key={trigger} value={trigger}>
                                {t(`enums.pull_trigger.${trigger}`)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.outcome} className="text-[12.5px] text-ink-2">
                        {t('admin.odk.history.filter_outcome')}
                    </label>
                    <select id={ids.outcome} className={select} value={filters.outcome ?? ''} onChange={(event) => visit({ outcome: event.target.value || null, page: 1 })}>
                        <option value="">{t('admin.odk.history.all_outcomes')}</option>
                        {OUTCOMES.map((outcome) => (
                            <option key={outcome} value={outcome}>
                                {t(`admin.odk.history.outcomes.${outcome}`)}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <AdminTable
                caption={t('admin.odk.history.caption')}
                captionHidden
                columns={columns}
                empty={<p className="px-3 py-6 text-center text-[13px] text-ink-2">{t('admin.odk.history.none')}</p>}
                meta={{ page: history.current_page, lastPage: history.last_page, from: history.from ?? 0, to: history.to ?? 0, total: history.total }}
                onPage={(page) => visit({ page })}
                labels={{
                    showing: (figures) => t('admin.odk.history.showing', figures),
                    none: t('admin.odk.history.showing_none'),
                    nav: t('admin.odk.history.pages'),
                }}
            >
                {history.data.map((row) => (
                    <tr key={row.id} className="border-b border-rule last:border-b-0">
                        <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                            {months.length === 12 ? formatDateTime(row.started_at, months) : row.started_at}
                        </th>
                        <td className="px-3 py-2 whitespace-nowrap">{t(`enums.pull_trigger.${row.trigger}`)}</td>
                        <td className="px-3 py-2">{row.triggered_by ?? t('admin.odk.history.system')}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.fetched)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.accepted)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.flagged)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.quarantined)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                            {row.duration === null
                                ? '–'
                                : row.duration.minutes === 0
                                  ? t('admin.odk.history.duration_seconds', { seconds: row.duration.seconds })
                                  : t('admin.odk.history.duration_value', row.duration)}
                        </td>
                        <td className="px-3 py-2">
                            <Outcome outcome={row.outcome} label={t(`admin.odk.history.outcomes.${row.outcome}`)} error={row.error} />
                        </td>
                    </tr>
                ))}
            </AdminTable>
        </Panel>
    );
}

function Outcome({ outcome, label, error }: { outcome: PullOutcomeValue; label: string; error: string | null }) {
    const style = OUTCOME_STYLE[outcome];

    return (
        <span className="flex flex-col gap-0.5">
            <span className={`inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-[11.5px] whitespace-nowrap ${style.tone}`}>
                <span aria-hidden="true" className="text-[9px]">
                    {style.mark}
                </span>
                {label}
            </span>
            {error !== null && <span className="max-w-72 text-[12px] break-words text-ink-2">{error}</span>}
        </span>
    );
}
