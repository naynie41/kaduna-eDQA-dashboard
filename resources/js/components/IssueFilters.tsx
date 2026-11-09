import { useId } from 'react';
import Labelled from '@/components/Labelled';
import { useRoundLabel } from '@/lib/dataIssues';
import { useTranslate } from '@/lib/i18n';
import type { DataIssuesFilters, IssueState, RoundRef, RuleSummary } from '@/types/admin';

/** A filter change, always from page one. */
export type IssueFilterChange = Partial<DataIssuesFilters> & { page: number };

export type IssueFiltersProps = {
    filters: DataIssuesFilters;
    rules: RuleSummary[];
    lgas: { code: string; name: string }[];
    rounds: (RoundRef & { status: string })[];
    /** Every configured source, in config order. */
    sources: { key: string; name: string }[];
    onChange: (next: IssueFilterChange) => void;
};

const STATES: IssueState[] = ['open', 'resolved', 'rejected', 'superseded'];
// The Deleted at source tab: not reviewed, or reviewed (kept or withdrawn).
const DELETED_STATES: IssueState[] = ['open', 'resolved'];

/**
 * The Data issues page's filters (ARCHITECTURE.md §9.3): state (not on the Soft tab), rule (not
 * on the Deleted at source tab), LGA, round and source. The page keeps them in the URL.
 */
export default function IssueFilters({ filters, rules, lgas, rounds, sources, onChange }: IssueFiltersProps) {
    const t = useTranslate();
    const roundLabel = useRoundLabel();
    const ids = { state: useId(), rule: useId(), lga: useId(), round: useId(), source: useId() };
    const select = 'control min-h-11';

    return (
        <div role="group" aria-label={t('admin.issues.filters.label')} className="flex flex-wrap items-end gap-3 px-5 pb-3">
            {filters.tab !== 'soft' && (
                <Labelled id={ids.state} label={t('admin.issues.filters.state')}>
                    <select id={ids.state} className={select} value={filters.state} onChange={(event) => onChange({ state: event.target.value as IssueState, page: 1 })}>
                        {(filters.tab === 'deleted' ? DELETED_STATES : STATES).map((state) => (
                            <option key={state} value={state}>
                                {filters.tab === 'deleted' ? t(`admin.issues.deleted.states.${state}`) : t(`admin.issues.filters.states.${state}`)}
                            </option>
                        ))}
                    </select>
                </Labelled>
            )}
            {filters.tab !== 'deleted' && (
                <Labelled id={ids.rule} label={t('admin.issues.filters.rule')}>
                    <select id={ids.rule} className={select} value={filters.rule ?? ''} onChange={(event) => onChange({ rule: event.target.value || null, page: 1 })}>
                        <option value="">{t('admin.issues.filters.all_rules')}</option>
                        {rules
                            .filter((rule) => rule.severity === filters.tab)
                            .map((rule) => (
                                <option key={rule.code} value={rule.code}>
                                    {rule.name}
                                </option>
                            ))}
                    </select>
                </Labelled>
            )}
            <Labelled id={ids.lga} label={t('admin.issues.filters.lga')}>
                <select id={ids.lga} className={select} value={filters.lga ?? ''} onChange={(event) => onChange({ lga: event.target.value || null, page: 1 })}>
                    <option value="">{t('admin.issues.filters.all_lgas')}</option>
                    {lgas.map((lga) => (
                        <option key={lga.code} value={lga.code}>
                            {lga.name}
                        </option>
                    ))}
                </select>
            </Labelled>
            <Labelled id={ids.round} label={t('admin.issues.filters.round')}>
                <select id={ids.round} className={select} value={filters.round ?? ''} onChange={(event) => onChange({ round: event.target.value === '' ? null : Number(event.target.value), page: 1 })}>
                    <option value="">{t('admin.issues.filters.all_rounds')}</option>
                    {rounds.map((round) => (
                        <option key={round.id} value={round.id}>
                            {roundLabel(round)}
                        </option>
                    ))}
                </select>
            </Labelled>
            <Labelled id={ids.source} label={t('admin.issues.filters.source')}>
                <select id={ids.source} className={select} value={filters.source ?? ''} onChange={(event) => onChange({ source: event.target.value || null, page: 1 })}>
                    <option value="">{t('admin.issues.filters.all_sources')}</option>
                    {sources.map((source) => (
                        <option key={source.key} value={source.key}>
                            {source.name}
                        </option>
                    ))}
                </select>
            </Labelled>
            <button type="button" className="btn btn-secondary min-h-11" onClick={() => onChange({ state: 'open', rule: null, lga: null, round: null, source: null, page: 1 })}>
                {t('admin.issues.filters.clear')}
            </button>
        </div>
    );
}
