import { router } from '@inertiajs/react';
import { useId } from 'react';
import AdminTable from '@/components/AdminTable';
import { useAnnounce } from '@/components/Announcer';
import Panel from '@/components/Panel';
import SourceTag from '@/components/SourceTag';
import StateMark from '@/components/StateMark';

import PanelHeader from '@/components/PanelHeader';
import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { Paginated, PullHistoryRow, PullOutcomeValue } from '@/types/admin';

export type PullHistoryFilters = { source: string | null; trigger: string | null; outcome: string | null };

export type PullHistoryPanelProps = {
    history: Paginated<PullHistoryRow>;
    filters: PullHistoryFilters;
    /** Every configured source, in config order: the source filter. */
    sources: { key: string; name: string }[];
};

const TRIGGERS = ['scheduled', 'manual', 'webhook', 'reconcile', 'backfill'] as const;
const OUTCOMES: PullOutcomeValue[] = ['succeeded', 'failed', 'skipped_not_configured', 'running'];

// An outcome in words, with a shape as well as a colour (CLAUDE.md hard rule 11).
const OUTCOME_STYLE: Record<PullOutcomeValue, { tone: string; mark: string }> = {
    succeeded: { tone: 'bg-ok-bg text-ok', mark: '●' },
    failed: { tone: 'bg-bad-bg text-bad', mark: '■' },
    skipped_not_configured: { tone: 'bg-paper text-ink-2', mark: '○' },
    running: { tone: 'bg-wash text-deep', mark: '◐' },
};

/**
 * The combined pull history (ARCHITECTURE.md §9.3): every source's pulls, newest first, 25 a
 * page from the server, filtered by source, trigger and outcome. A filter or page change reloads
 * only the history and says how many pulls are shown.
 */
export default function PullHistoryPanel({ history, filters, sources }: PullHistoryPanelProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const ids = { source: useId(), trigger: useId(), outcome: useId() };

    const visit = (next: Partial<PullHistoryFilters> & { page?: number }) => {
        const query = { ...filters, ...next };
        router.get(
            '/admin/sources',
            Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '')),
            {
                only: ['history', 'historyFilters'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: (page) => {
                    const total = (page.props.history as Paginated<PullHistoryRow> | undefined)?.total ?? 0;
                    announce(t('admin.sources.history.filtered', { total: formatCount(total) }));
                },
            },
        );
    };

    const columns = [
        { key: 'started', header: t('admin.sources.history.started') },
        { key: 'source', header: t('admin.sources.history.source') },
        { key: 'trigger', header: t('admin.sources.history.trigger') },
        { key: 'by', header: t('admin.sources.history.by') },
        { key: 'retrieved', header: t('admin.sources.history.retrieved'), align: 'end' as const },
        { key: 'accepted', header: t('admin.sources.history.accepted'), align: 'end' as const },
        { key: 'flagged', header: t('admin.sources.history.flagged'), align: 'end' as const },
        { key: 'quarantined', header: t('admin.sources.history.quarantined'), align: 'end' as const },
        { key: 'duplicates', header: t('admin.sources.history.duplicates'), align: 'end' as const },
        { key: 'cross', header: t('admin.sources.history.cross_source_duplicates'), align: 'end' as const },
        { key: 'duration', header: t('admin.sources.history.duration'), align: 'end' as const },
        { key: 'outcome', header: t('admin.sources.history.outcome') },
    ];

    const select = 'control min-h-11';

    return (
        <Panel labelledBy="sources-history-title">
            <PanelHeader id="sources-history-title" title={t('admin.sources.history.title')} />
            <div className="flex flex-wrap gap-3 px-5 pb-3">
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.source} className="text-xs text-ink-2">
                        {t('admin.sources.history.filter_source')}
                    </label>
                    <select id={ids.source} className={select} value={filters.source ?? ''} onChange={(event) => visit({ source: event.target.value || null, page: 1 })}>
                        <option value="">{t('admin.sources.history.all_sources')}</option>
                        {sources.map((source) => (
                            <option key={source.key} value={source.key}>
                                {source.name}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.trigger} className="text-xs text-ink-2">
                        {t('admin.sources.history.filter_trigger')}
                    </label>
                    <select id={ids.trigger} className={select} value={filters.trigger ?? ''} onChange={(event) => visit({ trigger: event.target.value || null, page: 1 })}>
                        <option value="">{t('admin.sources.history.all_triggers')}</option>
                        {TRIGGERS.map((trigger) => (
                            <option key={trigger} value={trigger}>
                                {t(`enums.pull_trigger.${trigger}`)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="flex flex-col gap-1">
                    <label htmlFor={ids.outcome} className="text-xs text-ink-2">
                        {t('admin.sources.history.filter_outcome')}
                    </label>
                    <select id={ids.outcome} className={select} value={filters.outcome ?? ''} onChange={(event) => visit({ outcome: event.target.value || null, page: 1 })}>
                        <option value="">{t('admin.sources.history.all_outcomes')}</option>
                        {OUTCOMES.map((outcome) => (
                            <option key={outcome} value={outcome}>
                                {t(`admin.sources.history.outcomes.${outcome}`)}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <AdminTable
                caption={t('admin.sources.history.caption')}
                captionHidden
                columns={columns}
                empty={<p className="px-3 py-6 text-center text-sm text-ink-2">{t('admin.sources.history.none')}</p>}
                meta={{ page: history.current_page, lastPage: history.last_page, from: history.from ?? 0, to: history.to ?? 0, total: history.total }}
                onPage={(page) => visit({ page })}
                labels={{
                    showing: (figures) => t('admin.sources.history.showing', figures),
                    none: t('admin.sources.history.showing_none'),
                    nav: t('admin.sources.history.pages'),
                }}
            >
                {history.data.map((row) => (
                    <tr key={row.id} className="border-b border-rule last:border-b-0">
                        <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                            {months.length === 12 ? formatDateTime(row.started_at, months) : row.started_at}
                        </th>
                        <td className="px-3 py-2 whitespace-nowrap">
                            <SourceTag source={row.source ?? null} />
                        </td>
                        <td className="px-3 py-2 whitespace-nowrap">{t(`enums.pull_trigger.${row.trigger}`)}</td>
                        <td className="px-3 py-2">{row.triggered_by ?? t('admin.sources.history.system')}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.fetched)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.accepted)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.flagged)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.quarantined)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.duplicates)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{formatCount(row.cross_source_duplicates)}</td>
                        <td className="px-3 py-2 text-right whitespace-nowrap tabular-nums">
                            {row.duration === null
                                ? '–'
                                : row.duration.minutes === 0
                                  ? t('admin.sources.history.duration_seconds', { seconds: row.duration.seconds })
                                  : t('admin.sources.history.duration_value', row.duration)}
                        </td>
                        <td className="px-3 py-2">
                            <Outcome outcome={row.outcome} label={t(`admin.sources.history.outcomes.${row.outcome}`)} error={row.error} />
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
            <span className={`inline-flex w-fit items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs whitespace-nowrap ${style.tone}`}>
                <StateMark mark={style.mark} className="size-2" />
                {label}
            </span>
            {error !== null && <span className="max-w-72 text-xs break-words text-ink-2">{error}</span>}
        </span>
    );
}
