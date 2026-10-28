import { Link, router } from '@inertiajs/react';
import { Fragment, useId, useState, type ReactNode } from 'react';
import AdminTable from '@/components/AdminTable';
import { useAnnounce } from '@/components/Announcer';
import ConfirmWithReasonDialog, { type ConfirmOutcome } from '@/components/ConfirmWithReasonDialog';
import CorrectIssueForm from '@/components/CorrectIssueForm';
import DataIssueDrawer from '@/components/DataIssueDrawer';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import RuleSummary from '@/components/RuleSummary';
import SegmentedControl from '@/components/SegmentedControl';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { bulkRule } from '@/lib/dataIssues';
import { useApiAction } from '@/lib/useApiAction';
import type { CorrectableField, DataIssueRow, DataIssuesFilters, FlaggedRow, IssueState, Paginated, Resolution, RoundRef, RuleSummary as Rule } from '@/types/admin';

export type AdminDataIssuesProps = {
    issueFilters: DataIssuesFilters;
    rules: Rule[];
    records: Paginated<DataIssueRow> | Paginated<FlaggedRow>;
    lgas: { code: string; name: string }[];
    rounds: (RoundRef & { status: string })[];
    correctable: CorrectableField[];
};

type Pending = { title: string; consequence: string; confirm: string; danger: boolean; send: (reason: string) => Promise<ConfirmOutcome> };
type Outcome = { text: string; rescore: RoundRef | null };

const STATES: IssueState[] = ['open', 'resolved', 'rejected', 'superseded'];

/**
 * The Data issues page (ARCHITECTURE.md §6, §9.3, D-57): what validation held back, and the
 * three ways out. Hard tab: quarantined records, corrected and reprocessed, or rejected, one at a
 * time or in bulk when every selected record fails the same single rule. Soft tab: accepted
 * assessments whose flags can be overridden. Every resolution asks for a reason
 * (ConfirmWithReasonDialog), says what happened, reloads only the rows, the rule counts and the
 * badge, and links to the round's rescore. All filters are in the URL.
 */
export default function AdminDataIssues({ issueFilters: filters, rules, records, lgas, rounds }: AdminDataIssuesProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const api = useApiAction<{ data: Resolution | Resolution[] }>();
    const ids = { state: useId(), rule: useId(), lga: useId(), round: useId(), bulkField: useId(), bulkValue: useId(), mixed: useId() };
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
                only: ['records', 'issueFilters', 'rules'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onSuccess: (page) => announce(t('admin.issues.filters.announce', { total: formatCount((page.props.records as Paginated<unknown> | undefined)?.total ?? 0) })),
            },
        );
    };
    const refresh = () => router.reload({ only: ['records', 'rules', 'admin', 'shell'] });

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

    const select = 'min-h-11 rounded-md border border-rule bg-panel px-2.5 text-[13.5px]';
    const button = 'min-h-11 rounded-md border border-rule bg-panel px-3 text-[13px] hover:bg-paper disabled:cursor-not-allowed disabled:opacity-50';
    const meta = { page: records.current_page, lastPage: records.last_page, from: records.from ?? 0, to: records.to ?? 0, total: records.total };
    const labels = {
        showing: (figures: { from: string; to: string; total: string }) => t('admin.issues.table.showing', figures),
        none: t('admin.issues.table.showing_none'),
        nav: t('admin.issues.table.pages'),
    };
    const empty = <p className="px-3 py-6 text-center text-[13px] text-ink-2">{t('admin.issues.table.none')}</p>;

    return (
        <div className="flex flex-col gap-5">
            <SegmentedControl
                label={t('admin.issues.tabs.label')}
                options={[
                    { value: 'hard', label: t('admin.issues.tabs.hard') },
                    { value: 'soft', label: t('admin.issues.tabs.soft') },
                ]}
                value={filters.tab}
                onChange={(tab) => visit({ tab, rule: null, page: 1 })}
            />

            <RuleSummary
                rules={rules.filter((rule) => rule.severity === filters.tab)}
                active={filters.rule}
                onChoose={(rule) => visit({ rule, page: 1 })}
            />

            <Panel labelledBy="issues-records-title">
                <PanelHeader id="issues-records-title" title={t(filters.tab === 'hard' ? 'admin.issues.tabs.hard' : 'admin.issues.tabs.soft')} />
                <div role="group" aria-label={t('admin.issues.filters.label')} className="flex flex-wrap items-end gap-3 px-5 pb-3">
                    {filters.tab === 'hard' && (
                        <Labelled id={ids.state} label={t('admin.issues.filters.state')}>
                            <select id={ids.state} className={select} value={filters.state} onChange={(event) => visit({ state: event.target.value as IssueState, page: 1 })}>
                                {STATES.map((state) => (
                                    <option key={state} value={state}>
                                        {t(`admin.issues.filters.states.${state}`)}
                                    </option>
                                ))}
                            </select>
                        </Labelled>
                    )}
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
                    <button type="button" className={button} onClick={() => visit({ state: 'open', rule: null, lga: null, round: null, page: 1 })}>
                        {t('admin.issues.filters.clear')}
                    </button>
                </div>

                {outcome !== null && (
                    <div role="status" className="mx-5 mb-3 rounded-md border border-ok bg-ok-bg px-3 py-2 text-[13px] text-ink">
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
                    <div role="group" aria-label={t('admin.issues.bulk.label')} className="mx-5 mb-3 flex flex-wrap items-end gap-3 rounded-md border border-rule bg-paper px-3 py-2.5 text-[13px]">
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
                                        <code className="block max-w-56 text-[12px] break-all">{row.instance_id}</code>
                                        <button type="button" className="mt-1 text-[12px] text-deep underline" onClick={() => copy(row.instance_id)} aria-label={t('admin.issues.table.copy', { instance: row.instance_id })}>
                                            {t('admin.issues.table.copy_short')}
                                        </button>
                                    </th>
                                    <td className="px-3 py-2">
                                        <ul className="flex flex-wrap gap-1">
                                            {row.failures.map((failure, i) => (
                                                <li key={`${failure.code}-${i}`}>
                                                    <code className="rounded bg-bad-bg px-1.5 py-px text-[11.5px] text-bad">{failure.code}</code>
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.facility_ref === null ? '–' : <code className="text-[12px]">{row.facility_ref}</code>}
                                        <span className="block text-[12px] text-ink-2">{row.facility_ref === null ? '' : (row.facility_name ?? t('admin.issues.table.facility_unknown'))}</span>
                                    </td>
                                    <td className="px-3 py-2">{row.lga_ref ?? '–'}</td>
                                    <td className="px-3 py-2 whitespace-nowrap">{roundLabel(row.round)}</td>
                                    <td className="px-3 py-2">
                                        <ul className="flex max-w-80 flex-col gap-1">
                                            {row.failures.map((failure, i) => (
                                                <li key={`${failure.code}-${i}`}>
                                                    <code className="text-[12px] break-words">{failure.detail}</code>
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">{when(row.received_at)}</td>
                                    <td className="px-3 py-2">
                                        <div className="flex flex-col items-start gap-1.5">
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
                                                <span className="text-[12.5px] text-ink-2">
                                                    {t('admin.issues.table.resolved', { resolution: t(`admin.issues.table.resolutions.${row.resolution ?? 'reprocessed'}`), time: when(row.resolved_at) })}
                                                </span>
                                            )}
                                            <button type="button" className={button} onClick={() => setDrawer(row)}>
                                                {t('admin.issues.actions.details')}
                                            </button>
                                            {row.odk_url !== null && (
                                                <a href={row.odk_url} target="_blank" rel="noreferrer" className="text-[12.5px] text-deep underline">
                                                    {t('admin.issues.actions.odk')} <span className="sr-only">{t('admin.issues.actions.new_tab')}</span>
                                                </a>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                                {correcting === row.id && (
                                    <tr className="border-b border-rule">
                                        <td colSpan={9} className="px-3 py-3">
                                            <CorrectIssueForm row={row} lgas={lgas} onReview={(corrections) => correct(row, corrections)} onCancel={() => setCorrecting(null)} />
                                        </td>
                                    </tr>
                                )}
                            </Fragment>
                        ))}
                    </AdminTable>
                ) : (
                    <AdminTable
                        caption={t('admin.issues.table.soft_caption')}
                        captionHidden
                        columns={[
                            { key: 'facility', header: t('admin.issues.table.facility') },
                            { key: 'lga', header: t('admin.issues.table.lga') },
                            { key: 'round', header: t('admin.issues.table.round') },
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
                                    <code className="block text-[12px] text-ink-2">{row.facility.code}</code>
                                </th>
                                <td className="px-3 py-2">{row.lga?.name ?? '–'}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{roundLabel(row.round)}</td>
                                <td className="px-3 py-2">{row.assessor}</td>
                                <td className="px-3 py-2 whitespace-nowrap">{months.length === 12 ? formatDate(row.started_at, months) : row.started_at}</td>
                                <td className="px-3 py-2">
                                    <ul className="flex flex-col gap-1.5">
                                        {row.flags.map((flag) => (
                                            <li key={flag.code} className="flex flex-wrap items-center gap-2">
                                                <code className="rounded bg-warn-bg px-1.5 py-px text-[11.5px] text-warn">{flag.code}</code>
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
            <label htmlFor={id} className="text-[12.5px] text-ink-2">
                {label}
            </label>
            {children}
        </div>
    );
}
