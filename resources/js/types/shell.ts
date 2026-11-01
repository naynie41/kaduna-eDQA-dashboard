import type { ScoreValue } from '@/types/score';

/**
 * Shared props for the shell, mirroring HandleInertiaRequests (`shell`) and ShareDashboardContext
 * (`filters`, `filterOptions`, `summary`, dashboard pages only).
 */
/** A source's sync state (SourceStatusAggregator); worst first: auth_failed, failed, stale, not_connected, healthy, disabled, not_configured. */
export type SyncState = 'not_configured' | 'disabled' | 'not_connected' | 'healthy' | 'stale' | 'failed' | 'auth_failed';

export type SyncStatus = {
    /** The source's key (odk, kobo) and its name as administrators see it. */
    key: string;
    name: string;
    state: SyncState;
    lastSuccessAt: string | null;
    minutesSinceSuccess: number | null;
    sinceSuccess: { value: number; unit: 'minutes' | 'hours' | 'days' } | null;
    lastFailedAt: string | null;
    lastError: string | null;
    /** The failed pull's error class, e.g. KoboUnreachable: what the bar names. */
    lastErrorClass: string | null;
    lastErrorHttpStatus: number | null;
    staleAfterMinutes: number;
};

export type ShellStatus = {
    assessments: number;
    /** Every source, worst state first. */
    sources: SyncStatus[];
    quarantined: number;
};

/** The admin pages' shared prop (HandleInertiaRequests, D-55). */
export type AdminShell = {
    /** Open quarantine records plus unreviewed deletions at source: the Data issues tab's badge (cached on the server). */
    openIssueCount: number;
    /** SECURITY.md §12: a round is open and these enabled sources have had no successful pull for `hours` (or ever). */
    staleIngestion: {
        stale: boolean;
        hours: number;
        round: { year: number; quarter: number } | null;
        sources: { key: string; name: string; lastSuccessAt: string | null }[];
    };
};

export type Filters = {
    year: number | null;
    quarter: number | null;
    lga: string | null;
    level: string | null;
    owner: string | null;
};

export type FilterKey = keyof Filters;

export type FilterOptions = {
    rounds: { year: number; quarter: number }[];
    lgas: { code: string; name: string }[];
    levels: { value: string; label: string }[];
    owners: { value: string; label: string }[];
};

export type Summary = {
    facilities: number;
    lgas: number;
    overall: ScoreValue;
};
