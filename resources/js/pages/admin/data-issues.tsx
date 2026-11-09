import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import ConfirmWithReasonDialog, { type ConfirmOutcome } from '@/components/ConfirmWithReasonDialog';
import DataIssueDrawer from '@/components/DataIssueDrawer';
import DeletedAtSourceTable from '@/components/DeletedAtSourceTable';
import FlaggedTable from '@/components/FlaggedTable';
import IssueBulkBar from '@/components/IssueBulkBar';
import IssueFilters from '@/components/IssueFilters';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import QuarantineTable from '@/components/QuarantineTable';
import RuleSummary from '@/components/RuleSummary';
import SegmentedControl from '@/components/SegmentedControl';
import { bulkRule } from '@/lib/dataIssues';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { CorrectableField, DataIssueRow, DataIssuesFilters, DeletedAssessmentRow, FlaggedRow, Paginated, Resolution, RoundRef, RuleSummary as Rule } from '@/types/admin';

export type AdminDataIssuesProps = {
    issueFilters: DataIssuesFilters;
    rules: Rule[];
    records: Paginated<DataIssueRow> | Paginated<FlaggedRow> | Paginated<DeletedAssessmentRow>;
    /** Assessments deleted at source, not reviewed yet: the third tab's count (D-62). */
    deletedOpen: number;
    lgas: { code: string; name: string }[];
    rounds: (RoundRef & { status: string })[];
    /** Every configured source, in config order: the source filter. */
    sources: { key: string; name: string }[];
    correctable: CorrectableField[];
};

type Pending = { title: string; consequence: string; confirm: string; danger: boolean; send: (reason: string) => Promise<ConfirmOutcome> };
type Outcome = { text: string; rescore: RoundRef | null };
type Corrections = Partial<Record<CorrectableField, string | number | null>>;

/**
 * The Data issues page (ARCHITECTURE.md §6, §9.3, D-57): what validation held back, and the
 * three ways out. Hard tab (QuarantineTable): quarantined records, corrected and reprocessed, or
 * rejected, one at a time or in bulk (IssueBulkBar) when every selected record fails the same
 * single rule. Soft tab (FlaggedTable): accepted assessments whose flags can be overridden. A
 * third tab (DeletedAtSourceTable) lists the assessments deleted at source, to keep, withdraw or
 * restore (D-62). Every resolution asks for a reason (ConfirmWithReasonDialog), says what
 * happened, reloads only the rows, the rule counts and the badge, and links to the round's
 * rescore. All filters (IssueFilters) are in the URL.
 */
export default function AdminDataIssues({ issueFilters: filters, rules, records, lgas, rounds, sources, deletedOpen }: AdminDataIssuesProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const api = useApiAction<{ data: Resolution | Resolution[] }>();

    const [pending, setPending] = useState<Pending | null>(null);
    const [outcome, setOutcome] = useState<Outcome | null>(null);
    const [correcting, setCorrecting] = useState<number | null>(null);
    const [drawer, setDrawer] = useState<DataIssueRow | null>(null);
    const [selected, setSelected] = useState<number[]>([]);

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
    const onPage = (page: number) => visit({ page });
    const refresh = () => router.reload({ only: ['records', 'rules', 'deletedOpen', 'admin', 'shell'] });

    /** Sends a resolution and turns its answer into the dialog's outcome and the page's message. */
    const send = async (url: string, body: Record<string, unknown>, describe: (data: Resolution | Resolution[]) => Outcome): Promise<ConfirmOutcome> => {
        const result = await api.run('POST', url, body);
        if (!result.ok) {
            return { ok: false, message: result.message, fieldError: result.fieldErrors.reason ?? null };
        }
        setOutcome(describe(result.data.data));
        setCorrecting(null);
        setSelected([]);
        refresh();

        return { ok: true };
    };

    /** Asks for the reason, then sends: every action below is one of these. */
    const ask = (key: string, replacements: Record<string, string | number>, danger: boolean, url: string, body: Record<string, unknown>, describe: (data: Resolution | Resolution[]) => Outcome, confirmKey = key) =>
        setPending({
            title: t(`admin.issues.dialogs.${key}_title`, replacements),
            consequence: t(`admin.issues.dialogs.${key}_consequence`, replacements),
            confirm: t(`admin.issues.dialogs.${confirmKey}_confirm`),
            danger,
            send: (reason) => send(url, { ...body, reason }, describe),
        });

    const describeOne = (data: Resolution | Resolution[]): Outcome => {
        const resolution = data as Resolution;
        if (resolution.record_status === 'open') {
            return { text: t('admin.issues.outcomes.still', { rules: resolution.failures.map((f) => f.code).join(', ') }), rescore: null };
        }
        const text = resolution.status === 'rejected' ? t('admin.issues.outcomes.rejected') : resolution.status === 'flagged' ? t('admin.issues.outcomes.flagged') : t('admin.issues.outcomes.accepted');

        return { text, rescore: resolution.rescore_round };
    };
    const fixed = (key: string, rescore: RoundRef | null = null) => (): Outcome => ({ text: t(`admin.issues.outcomes.${key}`), rescore });
    const resolveUrl = (row: DataIssueRow) => `/admin/api/quarantine/${row.id}/resolve`;
    const otherSource = (row: DataIssueRow) => ({ source: row.duplicate_of?.source?.name ?? t('admin.issues.table.source_unknown') });

    // The Hard tab, one record at a time; a cross-source duplicate (§5.8) keeps the accepted
    // assessment (this record is rejected) or lets this submission replace it.
    const reject = (row: DataIssueRow) => ask('reject', {}, true, resolveUrl(row), { action: 'reject' }, describeOne);
    const correct = (row: DataIssueRow, corrections: Corrections) => ask('correct', {}, false, resolveUrl(row), { action: 'correct', corrections }, describeOne);
    const keepExisting = (row: DataIssueRow) => ask('keep_existing', otherSource(row), false, resolveUrl(row), { action: 'reject' }, (data) => ({ ...describeOne(data), text: t('admin.issues.outcomes.rejected') }));
    const useThis = (row: DataIssueRow) => ask('use_this', otherSource(row), false, resolveUrl(row), { action: 'replace' }, (data) => ({ text: t('admin.issues.outcomes.replaced'), rescore: (data as Resolution).rescore_round }));

    // The Hard tab in bulk (D-57).
    const hardRows = filters.tab === 'hard' ? (records as Paginated<DataIssueRow>).data : [];
    const chosen = hardRows.filter((row) => selected.includes(row.id));
    const bulk = (action: 'reject' | 'correct', field: CorrectableField | null = null, corrections: Corrections = {}) => {
        const count = formatCount(chosen.length);
        const describe = (data: Resolution | Resolution[]): Outcome => {
            const list = data as Resolution[];

            return { text: t('admin.issues.outcomes.bulk', { count: formatCount(list.filter((r) => r.record_status === 'resolved').length) }), rescore: list[0]?.rescore_round ?? null };
        };
        const body = { ids: chosen.map((row) => row.id), action, ...(action === 'correct' ? { corrections } : {}) };
        const replacements = action === 'correct' && field !== null ? { count, field: t(`admin.issues.correct.fields.${field}`) } : { count };
        ask(`bulk_${action}`, replacements, action === 'reject', '/admin/api/quarantine/bulk-resolve', body, describe, action);
    };

    // The Soft tab.
    const override = (row: FlaggedRow, code: string, name: string) => ask('override', { rule: name }, false, `/admin/api/assessments/${row.id}/flags/${code}/override`, {}, fixed('overridden'));

    // The Deleted at source tab (D-62).
    const deletedRound = (row: DeletedAssessmentRow): RoundRef => ({ id: row.round.id, year: row.round.year, quarter: row.round.quarter });
    const keepDeleted = (row: DeletedAssessmentRow) =>
        ask('keep_deletion', { source: row.source?.name ?? t('admin.issues.table.source_unknown'), facility: row.facility.name }, false, `/admin/api/submissions/${row.submission_id}/deletion-review`, {}, fixed('kept'));
    const withdraw = (row: DeletedAssessmentRow) =>
        ask('withdraw', { facility: row.facility.name, quarter: row.round.quarter, year: row.round.year }, true, `/admin/api/assessments/${row.assessment.id}/withdraw`, {}, fixed('withdrawn', deletedRound(row)));
    const restore = (row: DeletedAssessmentRow) => ask('restore', { facility: row.facility.name }, false, `/admin/api/assessments/${row.assessment.id}/restore`, {}, fixed('restored', deletedRound(row)));

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

            {filters.tab !== 'deleted' && <RuleSummary rules={rules.filter((rule) => rule.severity === filters.tab)} active={filters.rule} onChoose={(rule) => visit({ rule, page: 1 })} />}

            <Panel labelledBy="issues-records-title">
                <PanelHeader
                    id="issues-records-title"
                    title={filters.tab === 'deleted' ? t('admin.issues.deleted.caption') : t(filters.tab === 'hard' ? 'admin.issues.tabs.hard' : 'admin.issues.tabs.soft')}
                />
                <IssueFilters filters={filters} rules={rules} lgas={lgas} rounds={rounds} sources={sources} onChange={visit} />

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
                    <IssueBulkBar
                        count={selected.length}
                        allowed={bulkRule(chosen) !== null}
                        onReject={() => bulk('reject')}
                        onCorrect={(field, corrections) => bulk('correct', field, corrections)}
                        onClear={() => setSelected([])}
                    />
                )}

                {filters.tab === 'hard' ? (
                    <QuarantineTable
                        records={records as Paginated<DataIssueRow>}
                        lgas={lgas}
                        selected={selected}
                        onSelectedChange={setSelected}
                        correcting={correcting}
                        onCorrectingChange={setCorrecting}
                        onReject={reject}
                        onCorrect={correct}
                        onKeepExisting={keepExisting}
                        onUseThis={useThis}
                        onDetails={setDrawer}
                        onPage={onPage}
                    />
                ) : filters.tab === 'deleted' ? (
                    <DeletedAtSourceTable records={records as Paginated<DeletedAssessmentRow>} onKeep={keepDeleted} onWithdraw={withdraw} onRestore={restore} onPage={onPage} />
                ) : (
                    <FlaggedTable records={records as Paginated<FlaggedRow>} onOverride={override} onPage={onPage} />
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
