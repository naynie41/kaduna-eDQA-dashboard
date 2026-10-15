import type { ScoreValue } from '@/types/score';

/**
 * Shared props for the shell, mirroring HandleInertiaRequests (`shell`) and ShareDashboardContext
 * (`filters`, `filterOptions`, `summary`, dashboard pages only).
 */
export type SyncState = 'not_connected' | 'ok' | 'stale' | 'failed';

export type SyncStatus = {
    state: SyncState;
    lastSuccessAt: string | null;
    minutesSinceSuccess: number | null;
    lastFailedAt: string | null;
    lastError: string | null;
    staleAfterMinutes: number;
};

export type ShellStatus = {
    assessments: number;
    sync: SyncStatus;
    quarantined: number;
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
