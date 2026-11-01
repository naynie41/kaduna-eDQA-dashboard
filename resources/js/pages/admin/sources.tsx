import { router } from '@inertiajs/react';
import { useState } from 'react';
import BackfillPanel from '@/components/BackfillPanel';
import CascadePanel from '@/components/CascadePanel';
import DeletionsPanel from '@/components/DeletionsPanel';
import PullAllPanel, { type PullAllRun } from '@/components/PullAllPanel';
import PullHistoryPanel, { type PullHistoryFilters } from '@/components/PullHistoryPanel';
import SourcePanel, { type FollowedRun } from '@/components/SourcePanel';
import type { BackfillStatus, DeletionRow, Paginated, PullHistoryRow, SourceEntry } from '@/types/admin';

export type AdminSourcesProps = {
    sources: SourceEntry[];
    backfill: BackfillStatus;
    history: Paginated<PullHistoryRow>;
    historyFilters: PullHistoryFilters;
    deletions: { rows: DeletionRow[]; total: number };
    docs: { runbook: string | null; cascade: string | null };
};

type Followed = Record<string, FollowedRun | null>;

const runsFrom = (sources: SourceEntry[], pick: (source: SourceEntry) => number | null): Followed =>
    Object.fromEntries(
        sources.map((source) => {
            const id = pick(source);

            return [source.key, id === null ? null : { id, attached: true }];
        }),
    );

/**
 * The Data sources page (ARCHITECTURE.md §9.3), replacing the ODK pull page: one panel per
 * source (ODK Central, KoboToolbox) with Pull now and Reconcile, Pull all, the combined pull
 * history, deletions at source for review, the backfill and the facility media file. The page
 * owns which run each panel follows, so Pull all's runs show in each source's own progress. A
 * finished run reloads the sources, the history and the deletions, not the whole page.
 */
export default function AdminSources({ sources, backfill, history, historyFilters, deletions, docs }: AdminSourcesProps) {
    const [pulls, setPulls] = useState<Followed>(() => runsFrom(sources, (source) => source.running_run_id));
    const [sweeps, setSweeps] = useState<Followed>(() => runsFrom(sources, (source) => source.running_reconcile_run_id));
    const refresh = () => router.reload({ only: ['sources', 'history', 'deletions', 'backfill', 'admin', 'shell'] });
    const names = Object.fromEntries(sources.map((source) => [source.key, source.name]));

    const followAll = (runs: PullAllRun[]) =>
        setPulls((current) => ({ ...current, ...Object.fromEntries(runs.map((run) => [run.source, { id: run.run_id, attached: run.already_running }])) }));

    return (
        <div className="flex flex-col gap-5">
            {sources.some((source) => source.enabled) && <PullAllPanel names={names} onStarted={followAll} />}
            <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
                {sources.map((source) => (
                    <SourcePanel
                        key={source.key}
                        source={source}
                        runbookUrl={docs.runbook}
                        pull={pulls[source.key] ?? null}
                        reconcile={sweeps[source.key] ?? null}
                        onPull={(run) => setPulls((current) => ({ ...current, [source.key]: run }))}
                        onReconcile={(run) => setSweeps((current) => ({ ...current, [source.key]: run }))}
                        onFinished={refresh}
                    />
                ))}
            </div>
            <PullHistoryPanel history={history} filters={historyFilters} sources={sources.map(({ key, name }) => ({ key, name }))} />
            <DeletionsPanel deletions={deletions} onReviewed={refresh} />
            <BackfillPanel backfill={backfill} />
            <CascadePanel howUrl={docs.cascade} />
        </div>
    );
}
