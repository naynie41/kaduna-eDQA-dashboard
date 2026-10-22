import type { SyncStatus } from '@/types/shell';

/** GET /api/odk/status, and the ODK pull page's `status` prop (OdkStatusPayload, D-56). */
export type OdkStatus = {
    configured: boolean;
    /** Env keys to set (never values). */
    problems: string[];
    reachable: { ok: boolean; status: string; message: string | null; checked_at: string } | null;
    cursor: { last_pulled_at: string | null; last_edit_at: string | null; last_instance_id: string | null; backfill_skip: number; submission_count: number } | null;
    running_run_id: number | null;
    last_run: PullCounters & { id: number; outcome: string | null; finished_at: string | null; parsed: number } | null;
    sync: SyncStatus & {
        sinceSuccess: { value: number; unit: 'minutes' | 'hours' | 'days' } | null;
        lastErrorHttpStatus: number | null;
    };
    open_rounds: { id: number; year: number; quarter: number; accepted: number; flagged: number; quarantined: number }[];
};

export type PullCounters = {
    fetched: number;
    accepted: number;
    flagged: number;
    quarantined: number;
    duplicates: number;
};

export type PullOutcomeValue = 'succeeded' | 'failed' | 'skipped_not_configured' | 'running';

/** GET /api/odk/pull/{run} (OdkPullRunResource). */
export type PullRun = PullCounters & {
    run_id: number;
    trigger: string;
    status: PullOutcomeValue;
    started_at: string;
    finished_at: string | null;
    parsed: number;
    error: string | null;
};

/** One row of the pull history (PullHistoryQuery). */
export type PullHistoryRow = PullCounters & {
    id: number;
    started_at: string;
    trigger: 'scheduled' | 'manual' | 'webhook' | 'backfill';
    triggered_by: string | null;
    parsed: number;
    duration: { minutes: number; seconds: number } | null;
    outcome: PullOutcomeValue;
    error: string | null;
};

/** Laravel's paginator as Inertia serialises it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

export type BackfillStatus = {
    run: (PullCounters & { id: number; outcome: string | null; started_at: string; finished_at: string | null }) | null;
    checkpoint: { position: number; page: number; page_size: number };
    report: { file: string; at: string; url: string } | null;
};

/** POST /api/facility-list/export-cascade. */
export type CascadeExport = {
    filename: string;
    lgas: number;
    wards: number;
    facilities: number;
    url: string;
    expires_at: string;
};
