import { router } from '@inertiajs/react';
import BackfillPanel from '@/components/BackfillPanel';
import CascadePanel from '@/components/CascadePanel';
import OdkConnectionPanel from '@/components/OdkConnectionPanel';
import PullHistoryPanel, { type PullHistoryFilters } from '@/components/PullHistoryPanel';
import PullNowPanel from '@/components/PullNowPanel';
import type { BackfillStatus, OdkStatus, Paginated, PullHistoryRow } from '@/types/admin';

export type AdminOdkProps = {
    status: OdkStatus;
    backfill: BackfillStatus;
    history: Paginated<PullHistoryRow>;
    historyFilters: PullHistoryFilters;
    docs: { runbook: string | null; cascade: string | null };
};

/**
 * The ODK pull page (ARCHITECTURE.md §9.3, D-56): the connection, Pull now with its progress,
 * the pull history, the backfill's status and the ODK facility file. After a pull finishes the
 * page reloads the connection, the history and the backfill panel, not the whole page.
 */
export default function AdminOdk({ status, backfill, history, historyFilters, docs }: AdminOdkProps) {
    const refresh = () => router.reload({ only: ['status', 'history', 'backfill', 'admin', 'shell'] });

    return (
        <div className="flex flex-col gap-5">
            <OdkConnectionPanel status={status} runbookUrl={docs.runbook} />
            {status.configured && <PullNowPanel runningRunId={status.running_run_id} onFinished={refresh} />}
            <PullHistoryPanel history={history} filters={historyFilters} />
            <BackfillPanel backfill={backfill} />
            <CascadePanel howUrl={docs.cascade} />
        </div>
    );
}
