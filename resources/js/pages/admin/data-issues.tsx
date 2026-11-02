import { Link, router } from '@inertiajs/react';
import { Fragment, useId, useState, type ReactNode } from 'react';
import AdminTable from '@/components/AdminTable';
import { useAnnounce } from '@/components/Announcer';
import ConfirmWithReasonDialog, { type ConfirmOutcome } from '@/components/ConfirmWithReasonDialog';
import CorrectIssueForm from '@/components/CorrectIssueForm';
import DataIssueDrawer from '@/components/DataIssueDrawer';
import DeletedAtSourceTable from '@/components/DeletedAtSourceTable';
import DuplicateSides from '@/components/DuplicateSides';
import Panel from '@/components/Panel';
import SourceTag from '@/components/SourceTag';
import PanelHeader from '@/components/PanelHeader';
import RuleSummary from '@/components/RuleSummary';
import SegmentedControl from '@/components/SegmentedControl';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { bulkRule } from '@/lib/dataIssues';
import { useApiAction } from '@/lib/useApiAction';
import type { CorrectableField, DataIssueRow, DataIssuesFilters, DeletedAssessmentRow, FlaggedRow, IssueState, Paginated, Resolution, RoundRef, RuleSummary as Rule } from '@/types/admin';

export type AdminDataIssuesProps = {
    issueFilters: DataIssuesFilters;
    rules: Rule[];
    records: Paginated<DataIssueRow> | Paginated<FlaggedRow> | Paginated<DeletedAssessmentRow>;
    /** Assessments deleted at source, not reviewed yet: the third tab's count (D-24). */
    deletedOpen: number;
    lgas: { code: string; name: string }[];
    rounds: (RoundRef & { status: string })[];
    /** Every configured source, in config order: the source filter. */
    sources: { key: string; name: string }[];
    correctable: CorrectableField[];
};

type Pending = { title: string; consequence: string; confirm: string; danger: boolean; send: (reason: string) => Promise<ConfirmOutcome> };
type Outcome = { text: string; rescore: RoundRef | null };

const STATES: IssueState[] = ['open', 'resolved', 'rejected', 'superseded'];
// The Deleted at source tab: not reviewed, or reviewed (kept or withdrawn).
const DELETED_STATES: IssueState[] = ['open', 'resolved'];

/**
 * The Data issues page (ARCHITECTURE.md §6, §9.3, D-57): what validation held back, and the
 * three ways out. Hard tab: quarantined records, corrected and reprocessed, or rejected, one at a
 * time or in bulk when every selected record fails the same single rule. Soft tab: accepted
 * assessments whose flags can be overridden. Every resolution asks for a reason
 * (ConfirmWithReasonDialog), says what happened, reloads only the rows, the rule counts and the
 * badge, and links to the round's rescore. All filters are in the URL, the source among them: each
 * row names its source and links to the submission there, and a duplicate shows both sides with
 * "Keep the existing one" or "Use this one instead" (§5.8). A third tab lists the assessments
 * deleted at source, to keep, withdraw or restore (D-24).
 */
export default function AdminDataIssues({ issueFilters: filters, rules, records, lgas, rounds, sources, deletedOpen }: AdminDataIssuesProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const api = useApiAction<{ data: Resolution | Resolution[] }>();
    const ids = { state: useId(), rule: useId(), lga: useId(), round: useId(), source: useId(), bulkField: useId(), bulkValue: useId(), mixed: useId() };
    const when = (value: string | null) => (value === null ? '' : months.length === 12 ? formatDateTime(value, months) : value);
    const roundLabel = (round: RoundRef | null) => (round === null ? t('admin.issues.table.no_round') : t('admin.issues.filters.round_option', { quarter: round.quarter, year: round.year }));

    const [pending, setPending] = useState<Pending | null>(null);
    const [outcome, setOutcome] = useState<Outcome | null>(null);
    const [correcting, setCorrecting] = useState<number | null>(null);
    const [drawer, setDrawer] = useState<DataIssueRow | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkField, setBulkField] = useState<CorrectableField>('lga_ref');
    const [bulkValue, setBulkValue] = useState('');

    const visit = (next: Partial<DataIssuesFilters> & { page?: number }) => {
        const query = { ...filters, ...next };
        setSelected([]);
        setCorrecting(null);
        router.get(
            '/admin/data-issues',
            Object.fromEntries(Object.entries(query).filter(([key, value]) => value !== null && value !== undefined && value !== '' && !(key === 'tab' && value === 'hard') && !(key === 'state' && value === 'open'))),
            {
                only: ['records', 'issueFilters', 'rules', 'deletedOpen'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: (page) => announce(t('admin.issues.filters.announce', { total: formatCount((page.props.records as Paginated<unknown> | undefined)?.total ?? 0) })),
            },
        );
    };
    const refresh = () => router.reload({ only: ['records', 'rules', 'deletedOpen', 'admin', 'shell'] });

    /** Sends a resolution and turns its answer into the dialog's outcome and the page's message. */
    const send = async (method: 'POST', url: string, body: Record<string, unknown>, describe: (data: Resolution | Resolution[]) => Outcome): Promise<ConfirmOutcome> => {
        const result = await api.run(method, url, body);
        if (!result.ok) {
            return { ok: false, message: result.message, fieldError: result.fieldErrors.reason ?? null };
        }
        setOutcome(describe(result.data.data));
        setCorrecting(null);
        setSelected([]);
        refresh();

        return { ok: true };
    };

    const describeOne = (resolution: Resolution): Outcome => {
        if (resolution.record_status === 'open') {
            return { text: t('admin.issues.outcomes.still', { rules: resolution.failures.map((f) => f.code).join(', ') }), rescore: null };
        }
        const text = resolution.status === 'rejected' ? t('admin.issues.outcomes.rejected') : resolution.status === 'flagged' ? t('admin.issues.outcomes.flagged') : t('admin.issues.outcomes.accepted');

        return { text, rescore: resolution.rescore_round };
    };

    const reject = (row: DataIssueRow) =>
        setPending({
            title: t('admin.issues.dialogs.reject_title'),
            consequence: t('admin.issues.dialogs.reject_consequence'),
            confirm: t('admin.issues.dialogs.reject_confirm'),
            danger: true,
            send: (reason) => send('POST', `/api/quarantine/${row.id}/resolve`, { action: 'reject', reason }, (data) => describeOne(data as Resolution)),
        });

    const correct = (row: DataIssueRow, corrections: Partial<Record<CorrectableField, string | number | null>>) =>
        setPending({
            title: t('admin.issues.dialogs.correct_title'),
            consequence: t('admin.issues.dialogs.correct_consequence'),
            confirm: t('admin.issues.dialogs.correct_confirm'),
            danger: false,
            send: (reason) => send('POST', `/api/quarantine/${row.id}/resolve`, { action: 'correct', corrections, reason }, (data) => describeOne(data as Resolution)),
        });

    // A cross-source duplicate (§5.8): keep the accepted assessment (this record is rejected), or
    // let this submission replace it.
    const keepExisting = (row: DataIssueRow) =>
        setPending({
            title: t('admin.issues.dialogs.keep_existing_title'),
            consequence: t('admin.issues.dialogs.keep_existing_consequence', { source: row.duplicate_of?.source?.name ?? t('admin.issues.table.source_unknown') }),
            confirm: t('admin.issues.dialogs.keep_existing_confirm'),
            danger: false,
            send: (reason) => send('POST', `/api/quarantine/${row.id}/resolve`, { action: 'reject', reason }, (data) => ({ ...describeOne(data as Resolution), text: t('admin.issues.outcomes.rejected') })),
        });

    const chooseThis = (row: DataIssueRow) =>
        setPending({
            title: t('admin.issues.dialogs.use_this_title'),
            consequence: t('admin.issues.dialogs.use_this_consequence', { source: row.duplicate_of?.source?.name ?? t('admin.issues.table.source_unknown') }),
            confirm: t('admin.issues.dialogs.use_this_confirm'),
            danger: false,
            send: (reason) => send('POST', `/api/quarantine/${row.id}/resolve`, { action: 'replace', reason }, (data) => ({ text: t('admin.issues.outcomes.replaced'), rescore: (data as Resolution).rescore_round })),
        });

    // The Deleted at source tab (D-24).
    const deletedRound = (row: DeletedAssessmentRow): RoundRef => ({ id: row.round.id, year: row.round.year, quarter: row.round.quarter });
    const keepDeleted = (row: DeletedAssessmentRow) =>
        setPending({
            title: t('admin.issues.dialogs.keep_deletion_title'),
            consequence: t('admin.issues.dialogs.keep_deletion_consequence', { source: row.source?.name ?? t('admin.issues.table.source_unknown'), facility: row.facility.name }),
            confirm: t('admin.issues.dialogs.keep_deletion_confirm'),
            danger: false,
            send: (reason) => send('POST', `/api/submissions/${row.submission_id}/deletion-review`, { reason }, () => ({ text: t('admin.issues.outcomes.kept'), rescore: null })),
        });
    const withdraw = (row: DeletedAssessmentRow) =>
        setPending({
            title: t('admin.issues.dialogs.withdraw_title'),
            consequence: t('admin.issues.dialogs.withdraw_consequence', { facility: row.facility.name, quarter: row.round.quarter, year: row.round.year }),
            confirm: t('admin.issues.dialogs.withdraw_confirm'),
            danger: true,
            send: (reason) => send('POST', `/api/assessments/${row.assessment.id}/withdraw`, { reason }, () => ({ text: t('admin.issues.outcomes.withdrawn'), rescore: deletedRound(row) })),
        });
    const restore = (row: DeletedAssessmentRow) =>
        setPending({
            title: t('admin.issues.dialogs.restore_title'),
            consequence: t('admin.issues.dialogs.restore_consequence', { facility: row.facility.name }),
            confirm: t('admin.issues.dialogs.restore_confirm'),
            danger: false,
            send: (reason) => send('POST', `/api/assessments/${row.assessment.id}/restore`, { reason }, () => ({ text: t('admin.issues.outcomes.restored'), rescore: deletedRound(row) })),
        });

    const override = (row: FlaggedRow, code: string, name: string) =>
        setPending({
            title: t('admin.issues.dialogs.override_title', { rule: name }),
            consequence: t('admin.issues.dialogs.override_consequence'),
            confirm: t('admin.issues.dialogs.override_confirm'),
            danger: false,
            send: (reason) => send('POST', `/api/assessments/${row.id}/flags/${code}/override`, { reason }, () => ({ text: t('admin.issues.outcomes.overridden'), rescore: null })),
        });

    const hardRows = filters.tab === 'hard' ? (records as Paginated<DataIssueRow>).data : [];
    const chosen = hardRows.filter((row) => selected.includes(row.id));
    const sharedRule = bulkRule(chosen);
    const bulk = (action: 'reject' | 'correct') => {
        const count = formatCount(chosen.length);
        const corrections = { [bulkField]: bulkField === 'round_year' || bulkField === 'round_quarter' ? (bulkValue === '' ? null : Number(bulkValue)) : bulkValue === '' ? null : bulkValue };
        setPending({
            title: action === 'reject' ? t('admin.issues.dialogs.bulk_reject_title', { count }) : t('admin.issues.dialogs.bulk_correct_title', { count, field: t(`admin.issues.correct.fields.${bulkField}`) }),
            consequence: action === 'reject' ? t('admin.issues.dialogs.bulk_reject_consequence') : t('admin.issues.dialogs.bulk_correct_consequence'),
            confirm: action === 'reject' ? t('admin.issues.dialogs.reject_confirm') : t('admin.issues.dialogs.correct_confirm'),
            danger: action === 'reject',
            send: (reason) =>
                send('POST', '/api/quarantine/bulk-resolve', { ids: chosen.map((row) => row.id), action, reason, ...(action === 'correct' ? { corrections } : {}) }, (data) => {
                    const list = data as Resolution[];

                    return { text: t('admin.issues.outcomes.bulk', { count: formatCount(list.filter((r) => r.record_status === 'resolved').length) }), rescore: list[0]?.rescore_round ?? null };
                }),
        });
    };

    const copy = (instance: string) => {
        void navigator.clipboard?.writeText(instance).then(() => announce(t('admin.issues.table.copied')));
    };

    const select = 'control min-h-11';
    const button = 'btn btn-secondary min-h-11';
    const meta = { page: records.current_page, lastPage: records.last_page, from: records.from ?? 0, to: records.to ?? 0, total: records.total };
    const labels = {
        showing: (figures: { from: string; to: string; total: string }) => t('admin.issues.table.showing', figures),
        none: t('admin.issues.table.showing_none'),
        nav: t('admin.issues.table.pages'),
    };
    const empty = <p className="px-3 py-6 text-center text-sm text-ink-2">{t('admin.issues.table.none')}</p>;

    return (
        <div className="flex flex-col gap-5">
            <SegmentedControl
                label={t('admin.issues.tabs.label')}
                options={[
                    { value: 'hard', label: t('admin.issues.tabs.hard') },
                    { value: 'soft', label: t('admin.issues.tabs.soft') },
                    { value: 'deleted', label: t('admin.issues.tabs.deleted', { count: formatCount(deletedOpen) }) },
                ]}
                value={filters.tab}
                onChange={(tab) => visit({ tab, rule: null, state: 'open', page: 1 })}
            />

            {filters.tab !== 'deleted' && (
                <RuleSummary
                    rules={rules.filter((rule) => rule.severity === filters.tab)}
                    active={filters.rule}
                    onChoose={(rule) => visit({ rule, page: 1 })}
                />
            )}

            <Panel labelledBy="issues-records-title">
                <PanelHeader
                    id="issues-records-title"
                    title={filters.tab === 'deleted' ? t('admin.issues.deleted.caption') : t(filters.tab === 'hard' ? 'admin.issues.tabs.hard' : 'admin.issues.tabs.soft')}
                />
                <div role="group" aria-label={t('admin.issues.filters.label')} className="flex flex-wrap items-end gap-3 px-5 pb-3">
                    {filters.tab !== 'soft' && (
                        <Labelled id={ids.state} label={t('admin.issues.filters.state')}>
                            <select id={ids.state} className={select} value={filters.state} onChange={(event) => visit({ state: event.target.value as IssueState, page: 1 })}>
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
                        <select id={ids.rule} className={select} value={filters.rule ?? ''} onChange={(event) => visit({ rule: event.target.value || null, page: 1 })}>
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
                        <select id={ids.lga} className={select} value={filters.lga ?? ''} onChange={(event) => visit({ lga: event.target.value || null, page: 1 })}>
                            <option value="">{t('admin.issues.filters.all_lgas')}</option>
                            {lgas.map((lga) => (
                                <option key={lga.code} value={lga.code}>
                                    {lga.name}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <Labelled id={ids.round} label={t('admin.issues.filters.round')}>
                        <select id={ids.round} className={select} value={filters.round ?? ''} onChange={(event) => visit({ round: event.target.value === '' ? null : Number(event.target.value), page: 1 })}>
                            <option value="">{t('admin.issues.filters.all_rounds')}</option>
                            {rounds.map((round) => (
                                <option key={round.id} value={round.id}>
                                    {roundLabel(round)}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <Labelled id={ids.source} label={t('admin.issues.filters.source')}>
                        <select id={ids.source} className={select} value={filters.source ?? ''} onChange={(event) => visit({ source: event.target.value || null, page: 1 })}>
                            <option value="">{t('admin.issues.filters.all_sources')}</option>
                            {sources.map((source) => (
                                <option key={source.key} value={source.key}>
                                    {source.name}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <button type="button" className={button} onClick={() => visit({ state: 'open', rule: null, lga: null, round: null, source: null, page: 1 })}>
                        {t('admin.issues.filters.clear')}
                    </button>
                </div>

                {outcome !== null && (
                    <div role="status" className="notice notice-ok mx-5 mb-3">
                        <p>{outcome.text}</p>
                        {outcome.rescore !== null && (
                            <p>
                                {t('admin.issues.outcomes.rescore', { quarter: outcome.rescore.quarter, year: outcome.rescore.year })}{' '}
                                <Link href={`/admin/rounds?round=${outcome.rescore.id}`} className="font-medium text-deep underline">
                                    {t('admin.issues.outcomes.rescore_link')}
                                </Link>
                            </p>
                        )}
                    </div>
                )}

                {filters.tab === 'hard' && selected.length > 0 && (
                    <div role="group" aria-label={t('admin.issues.bulk.label')} className="mx-5 mb-3 flex flex-wrap items-end gap-3 rounded-md border border-rule bg-paper px-3 py-2.5 text-sm">
                        <p className="self-center font-medium">{t('admin.issues.bulk.selected', { count: formatCount(selected.length) })}</p>
                        {sharedRule === null && (
                            <p id={ids.mixed} className="self-center text-bad">
                                {t('admin.issues.bulk.mixed')}
                            </p>
                        )}
                        <button type="button" className={button} disabled={sharedRule === null} aria-describedby={sharedRule === null ? ids.mixed : undefined} onClick={() => bulk('reject')}>
                            {t('admin.issues.bulk.reject')}
                        </button>
                        <Labelled id={ids.bulkField} label={t('admin.issues.bulk.field')}>
                            <select id={ids.bulkField} className={select} value={bulkField} onChange={(event) => setBulkField(event.target.value as CorrectableField)} disabled={sharedRule === null}>
                                {(['lga_ref', 'ward_ref', 'facility_ref', 'raw_start', 'raw_end', 'round_year', 'round_quarter'] as const).map((field) => (
                                    <option key={field} value={field}>
                                        {t(`admin.issues.correct.fields.${field}`)}
                                    </option>
                                ))}
                            </select>
                        </Labelled>
                        <Labelled id={ids.bulkValue} label={t('admin.issues.bulk.value')}>
                            <input id={ids.bulkValue} className={select} value={bulkValue} onChange={(event) => setBulkValue(event.target.value)} disabled={sharedRule === null} />
                        </Labelled>
                        <button type="button" className={button} disabled={sharedRule === null} aria-describedby={sharedRule === null ? ids.mixed : undefined} onClick={() => bulk('correct')}>
                            {t('admin.issues.bulk.correct')}
                        </button>
                        <button type="button" className={button} onClick={() => setSelected([])}>
                            {t('admin.issues.bulk.clear')}
                        </button>
                    </div>
                )}

                {filters.tab === 'hard' ? (
                    <AdminTable
                        caption={t('admin.issues.table.caption')}
                        captionHidden
                        columns={[
                            { key: 'select', header: '' },
                            { key: 'instance', header: t('admin.issues.table.instance') },
                            { key: 'source', header: t('admin.issues.table.source') },
                            { key: 'rules', header: t('admin.issues.table.rules') },
                            { key: 'facility', header: t('admin.issues.table.facility') },
                            { key: 'lga', header: t('admin.issues.table.lga') },
                            { key: 'round', header: t('admin.issues.table.round') },
                            { key: 'detail', header: t('admin.issues.table.detail') },
                            { key: 'received', header: t('admin.issues.table.received') },
                            { key: 'actions', header: t('admin.issues.table.actions') },
                        ]}
                        empty={empty}
                        meta={meta}
                        onPage={(page) => visit({ page })}
                        labels={labels}
                    >
                        {(records as Paginated<DataIssueRow>).data.map((row) => (
                            <Fragment key={row.id}>
                                <tr className="border-b border-rule align-top">
                                    <td className="px-3 py-2">
                                        {row.status === 'open' && (
                                            <input
                                                type="checkbox"
                                                className="h-5 w-5"
                                                aria-label={t('admin.issues.table.select', { instance: row.instance_id })}
                                                checked={selected.includes(row.id)}
                                                onChange={(event) => setSelected((list) => (event.target.checked ? [...list, row.id] : list.filter((id) => id !== row.id)))}
                                            />
                                        )}
                                    </td>
                                    <th scope="row" className="px-3 py-2 text-left font-normal">
                                        <code className="block max-w-56 text-xs break-all">{row.instance_id}</code>
                                        <button type="button" className="mt-1 text-xs text-deep underline" onClick={() => copy(row.instance_id)} aria-label={t('admin.issues.table.copy', { instance: row.instance_id })}>
                                            {t('admin.issues.table.copy_short')}
                                        </button>
                                    </th>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                    <SourceTag source={row.source ?? null} />
                                </td>
                                    <td className="px-3 py-2">
                                        <ul className="flex flex-wrap gap-1">
                                            {row.failures.map((failure, i) => (
                                                <li key={`${failure.code}-${i}`}>
                                                    <code className="rounded bg-bad-bg px-1.5 py-px text-2xs text-bad">{failure.code}</code>
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.facility_ref === null ? '–' : <code className="text-xs">{row.facility_ref}</code>}
                                        <span className="block text-xs text-ink-2">{row.facility_ref === null ? '' : (row.facility_name ?? t('admin.issues.table.facility_unknown'))}</span>
                                    </td>
                                    <td className="px-3 py-2">{row.lga_ref ?? '–'}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{roundLabel(row.round)}</td>
                                    <td className="px-3 py-2">
                                        <ul className="flex max-w-80 flex-col gap-1">
                                            {row.failures.map((failure, i) => (
                                                <li key={`${failure.code}-${i}`}>
                                                    <code className="text-xs break-words">{failure.detail}</code>
                                                    {failure.code === 'DUPLICATE_ASSESSMENT' && <DuplicateSides row={row} />}
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">{when(row.received_at)}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex flex-col items-start gap-1.5">
                                            {row.status === 'open' && row.duplicate_of !== null && row.failures.every((failure) => failure.code === 'DUPLICATE_ASSESSMENT') && (
                                                <>
                                                    <button type="button" className="btn btn-primary min-h-11" onClick={() => keepExisting(row)}>
                                                        {t('admin.issues.actions.keep_existing')}
                                                    </button>
                                                    <button type="button" className={button} onClick={() => chooseThis(row)}>
                                                        {t('admin.issues.actions.use_this')}
                                                    </button>
                                                </>
                                            )}
                                            {row.status === 'open' ? (
                                                <>
                                                    <button type="button" className={button} aria-expanded={correcting === row.id} onClick={() => setCorrecting(correcting === row.id ? null : row.id)}>
                                                        {t('admin.issues.actions.correct')}
                                                    </button>
                                                    <button type="button" className={button} onClick={() => reject(row)}>
                                                        {t('admin.issues.actions.reject')}
                                                    </button>
                                                </>
                                            ) : (
                                                <span className="text-xs text-ink-2">
                                                    {t('admin.issues.table.resolved', { resolution: t(`admin.issues.table.resolutions.${row.resolution ?? 'reprocessed'}`), time: when(row.resolved_at) })}
                                                </span>
                                            )}
                                            <button type="button" className={button} onClick={() => setDrawer(row)}>
                                                {t('admin.issues.actions.details')}
                                            </button>
                                            {row.source !== null && row.source_url !== null && (
                                                <a href={row.source_url} target="_blank" rel="noopener noreferrer" className="text-xs text-deep underline">
                                                    {t('admin.issues.actions.open_in_source', { source: row.source.name })} <span className="sr-only">{t('admin.issues.actions.new_tab')}</span>
                                                </a>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                                {correcting === row.id && (
                                    <tr className="border-b border-rule">
                                        <td colSpan={10} className="px-3 py-3">
                                            <CorrectIssueForm row={row} lgas={lgas} onReview={(corrections) => correct(row, corrections)} onCancel={() => setCorrecting(null)} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </AdminTable>
                ) : filters.tab === 'deleted' ? (
                    <DeletedAtSourceTable
                        records={records as Paginated<DeletedAssessmentRow>}
                        onKeep={keepDeleted}
                        onWithdraw={withdraw}
                        onRestore={restore}
                        onPage={(page) => visit({ page })}
                    />
                ) : (
                    <AdminTable
                        caption={t('admin.issues.table.soft_caption')}
                        captionHidden
                        columns={[
                            { key: 'facility', header: t('admin.issues.table.facility') },
                            { key: 'lga', header: t('admin.issues.table.lga') },
                            { key: 'round', header: t('admin.issues.table.round') },
                            { key: 'source', header: t('admin.issues.table.source') },
                            { key: 'assessor', header: t('admin.issues.table.assessor') },
                            { key: 'visited', header: t('admin.issues.table.visited') },
                            { key: 'flags', header: t('admin.issues.table.flags') },
                        ]}
                        empty={empty}
                        meta={meta}
                        onPage={(page) => visit({ page })}
                        labels={labels}
                    >
                        {(records as Paginated<FlaggedRow>).data.map((row) => (
                            <tr key={row.id} className="border-b border-rule align-top">
                                <th scope="row" className="px-3 py-2 text-left font-normal">
                                    {row.facility.name}
                                    <code className="block text-xs text-ink-2">{row.facility.code}</code>
                                </th>
                                <td className="px-3 py-2">{row.lga?.name ?? '–'}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{roundLabel(row.round)}</td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    <SourceTag source={row.source ?? null} />
                                </td>
                                <td className="px-3 py-2">{row.assessor}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{months.length === 12 ? formatDate(row.started_at, months) : row.started_at}</td>
                                <td className="px-3 py-2">
                                    <ul className="flex flex-col gap-1.5">
                                        {row.flags.map((flag) => (
                                            <li key={flag.code} className="flex flex-wrap items-center gap-2">
                                                <code className="rounded bg-warn-bg px-1.5 py-px text-2xs text-warn">{flag.code}</code>
                                                <button type="button" className={button} onClick={() => override(row, flag.code, flag.name)}>
                                                    {t('admin.issues.actions.override', { rule: flag.name })}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </td>
                            </tr>
                        ))}
                    </AdminTable>
                )}
            </Panel>

            <ConfirmWithReasonDialog
                open={pending !== null}
                title={pending?.title ?? ''}
                consequence={pending?.consequence ?? ''}
                confirmLabel={pending?.confirm ?? ''}
                danger={pending?.danger ?? false}
                onConfirm={(reason) => (pending === null ? Promise.resolve({ ok: true }) : pending.send(reason))}
                onClose={() => setPending(null)}
            />
            {drawer !== null && <DataIssueDrawer recordId={drawer.id} instanceId={drawer.instance_id} onClose={() => setDrawer(null)} />}
        </div>
    );
}

function Labelled({ id, label, children }: { id: string; label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-xs text-ink-2">
                {label}
            </label>
            {children}
        </div>
    );
}
