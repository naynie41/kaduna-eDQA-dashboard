import type { SyncStatus } from '@/types/shell';

/** One open round's counts so far: accepted and flagged assessments, open quarantine records. */
export type OpenRoundCounts = { id: number; year: number; quarter: number; accepted: number; flagged: number; quarantined: number };

/** One source's entry in GET /api/sources and the Data sources page's `sources` prop (SourcesPayload). */
export type SourceEntry = {
    key: string;
    name: string;
    type: 'odk_central' | 'kobo';
    configured: boolean;
    /** Env keys to set (never values). */
    problems: string[];
    enabled: boolean;
    reachable: { ok: boolean; status: string; message: string | null; checked_at: string } | null;
    cursor: { last_pulled_at: string | null; last_edit_at: string | null; last_instance_id: string | null; backfill_skip: number; submission_count: number } | null;
    last_reconciled_at: string | null;
    running_run_id: number | null;
    running_reconcile_run_id: number | null;
    last_run: (PullCounters & { id: number; outcome: string | null; finished_at: string | null; parsed: number; cross_source_duplicates: number }) | null;
    status: SyncStatus;
    /** This source's own counts in each open round. */
    open_rounds: OpenRoundCounts[];
};

/** A submission its source no longer returns (DeletionsAtSourceQuery): flagged for review, never applied (D-62). */
export type DeletionRow = {
    submission_id: number;
    source: { key: string; name: string } | null;
    instance_id: string;
    deleted_at: string;
    /** What the portal holds for it: the assessment built from it, or its quarantine record. */
    facility: { id: number; code: string; name: string } | null;
    round: RoundRef | null;
    assessment: { id: number; status: string } | null;
    quarantined_record_id: number | null;
    source_url: string | null;
};

export type PullCounters = {
    fetched: number;
    accepted: number;
    flagged: number;
    quarantined: number;
    duplicates: number;
};

export type PullOutcomeValue = 'succeeded' | 'failed' | 'skipped_not_configured' | 'running';

/** GET /api/pulls/{run} (PullRunResource). */
export type PullRun = PullCounters & {
    run_id: number;
    /** The source's key (odk, kobo). */
    source: string | null;
    cross_source_duplicates: number;
    deletions_detected: number;
    trigger: string;
    status: PullOutcomeValue;
    started_at: string;
    finished_at: string | null;
    parsed: number;
    error: string | null;
};

/** One row of the pull history (PullHistoryQuery), every source's. */
export type PullHistoryRow = PullCounters & {
    id: number;
    source: { key: string; name: string } | null;
    started_at: string;
    trigger: 'scheduled' | 'manual' | 'webhook' | 'backfill' | 'reconcile';
    cross_source_duplicates: number;
    deletions_detected: number;
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

/** The Data issues page (D-57). */
export type IssueTab = 'hard' | 'soft';
export type IssueState = 'open' | 'resolved' | 'rejected' | 'superseded';

export type DataIssuesFilters = {
    tab: IssueTab;
    state: IssueState;
    rule: string | null;
    lga: string | null;
    round: number | null;
    source: string | null;
};

export type RuleSummary = {
    code: string;
    severity: 'hard' | 'soft';
    name: string;
    why: string;
    open: number;
};

export type Failure = { code: string; severity: string; name: string; detail: string; field: string | null };

export type RoundRef = { id: number; year: number; quarter: number };

export type CorrectableField = 'lga_ref' | 'ward_ref' | 'facility_ref' | 'raw_start' | 'raw_end' | 'round_year' | 'round_quarter';

/** DataIssueRowResource. */
export type DataIssueRow = {
    id: number;
    instance_id: string;
    submission_id: number;
    failures: Failure[];
    facility_ref: string | null;
    facility_name: string | null;
    lga_ref: string | null;
    round: RoundRef | null;
    received_at: string | null;
    status: 'open' | 'resolved';
    resolution: 'reprocessed' | 'rejected' | 'superseded' | null;
    resolution_note: string | null;
    resolved_at: string | null;
    corrections: Partial<Record<CorrectableField, string | number | null>>;
    current: Record<CorrectableField, string | number | null>;
    /** The submission's source, and a link to it there (null while its link settings are not configured). */
    source: { key: string; name: string } | null;
    source_url: string | null;
    /** DUPLICATE_ASSESSMENT: the accepted assessment it repeats, from whichever source (null for any other record, or once that assessment is gone). */
    duplicate_of: {
        assessment_id: number;
        facility_id: number;
        source: { key: string; name: string } | null;
        instance_id: string;
        url: string | null;
    } | null;
};

/** DataIssueDetailResource: the row plus what the drawer shows. */
export type DataIssueDetail = DataIssueRow & {
    parsed: Record<string, unknown>;
    payload: Record<string, unknown>;
    history: {
        id: number;
        event: string;
        at: string | null;
        by: string | null;
        reason: string | null;
        old: Record<string, unknown>;
        new: Record<string, unknown>;
    }[];
};

/** FlaggedAssessmentResource. */
export type FlaggedRow = {
    id: number;
    facility: { id: number; code: string; name: string };
    lga: { code: string; name: string } | null;
    round: RoundRef;
    assessor: string;
    started_at: string;
    flags: { code: string; name: string }[];
    source: { key: string; name: string } | null;
};

/** QuarantineResolutionResource: what a resolve or bulk resolve answers per record. */
export type Resolution = {
    quarantined_record_id: number;
    instance_id: string;
    failures: Failure[];
    record_status: 'open' | 'resolved';
    /** The submission's status after: accepted, flagged, quarantined, rejected. */
    status: string;
    assessment_id: number | null;
    rescore_round: RoundRef | null;
};
