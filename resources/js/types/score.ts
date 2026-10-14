/**
 * Mirror of App\Domain\Scoring\DTOs\ScoreValue: the only shape in which a score reaches the
 * frontend (CONVENTION.md §4). Everything a person reads is decided on the server; components
 * render these fields and never round, band or compare `value`.
 */
export type Band = 'strong' | 'acceptable' | 'review' | 'needs_action';

export type ScoreValue = {
    /** Full precision, for sorting and charts only; null when there is no score. */
    value: number | null;
    /** 1 decimal, en-NG, already rounded (Q-09); "—" when there is no score. */
    display: string;
    /** Whole number for the big figures; "—" when there is no score. */
    headline: string;
    /** Classified on the displayed value (Q-26); null when there is no score. */
    band: Band | null;
    /** The band in words, from the language file; "No data" when there is no score. */
    bandLabel: string;
};
