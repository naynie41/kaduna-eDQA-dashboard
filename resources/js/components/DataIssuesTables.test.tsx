import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import FlaggedTable from '@/components/FlaggedTable';
import IssueBulkBar from '@/components/IssueBulkBar';
import IssueFilters from '@/components/IssueFilters';
import QuarantineTable from '@/components/QuarantineTable';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { DataIssueRow, DataIssuesFilters, FlaggedRow, Paginated } from '@/types/admin';

// The Data issues page's parts (ARCHITECTURE.md §9.3, D-57): the filters, the bulk bar, and the
// Hard and Soft tabs' tables. The page wires them to its dialog; each part only reports intent.

const translations = {
    ...testTranslations,
    admin: {
        issues: {
            filters: {
                label: 'Filters', state: 'State', rule: 'Rule', lga: 'LGA', round: 'Round', source: 'Source', clear: 'Clear filters',
                all_rules: 'All rules', all_lgas: 'All LGAs', all_rounds: 'All rounds', all_sources: 'All sources', round_option: 'Q:quarter :year',
                states: { open: 'Open', resolved: 'Resolved', rejected: 'Rejected', superseded: 'Superseded' },
            },
            bulk: { label: 'Bulk actions', selected: ':count selected', mixed: 'Pick records failing one rule.', reject: 'Reject selected', correct: 'Correct selected', field: 'Field', value: 'New value', clear: 'Clear selection' },
            correct: { fields: { lga_ref: 'LGA', ward_ref: 'Ward', facility_ref: 'Facility', raw_start: 'Visit start', raw_end: 'Visit end', round_year: 'Round year', round_quarter: 'Round quarter' } },
            table: {
                caption: 'Quarantined records', soft_caption: 'Flagged assessments', none: 'Nothing here.', select: 'Select :instance', select_header: 'Select for a bulk action', copy: 'Copy :instance', copy_short: 'Copy',
                no_round: 'No round', facility_unknown: 'Unknown facility', showing: ':from–:to of :total', showing_none: 'None', pages: 'Pages',
                instance: 'Instance', source: 'Source', rules: 'Rules', facility: 'Facility', lga: 'LGA', round: 'Round', detail: 'Detail', received: 'Received', actions: 'Actions',
                assessor: 'Assessor', visited: 'Visited', flags: 'Flags',
            },
            actions: { correct: 'Correct', reject: 'Reject', details: 'Details', keep_existing: 'Keep the existing one', use_this: 'Use this one instead', override: 'Override :rule', open_in_source: 'Open in :source', new_tab: '(new tab)' },
        },
    },
};

const page = <T,>(data: T[]): Paginated<T> => ({ data, current_page: 1, last_page: 1, from: data.length === 0 ? null : 1, to: data.length, total: data.length });

const held = (id: number, code = 'LGA_UNKNOWN', extra: Partial<DataIssueRow> = {}): DataIssueRow => ({
    id,
    instance_id: `uuid:${id}`,
    submission_id: id * 10,
    failures: [{ code, severity: 'hard', name: code, detail: `${code} detail`, field: null }],
    facility_ref: 'CHK-0001',
    facility_name: 'Kujama PHC',
    lga_ref: 'CHK',
    round: { id: 1, year: 2026, quarter: 2 },
    received_at: '2026-04-14T10:00:00+01:00',
    status: 'open',
    resolution: null,
    resolution_note: null,
    resolved_at: null,
    corrections: {},
    current: { lga_ref: 'CHK', ward_ref: null, facility_ref: 'CHK-0001', raw_start: null, raw_end: null, round_year: 2026, round_quarter: 2 },
    source: { key: 'kobo', name: 'KoboToolbox' },
    source_url: null,
    duplicate_of: null,
    ...extra,
});

const filters: DataIssuesFilters = { tab: 'hard', state: 'open', rule: null, lga: null, round: null, source: null };

function quarantineTable(rows: DataIssueRow[], overrides: Partial<Parameters<typeof QuarantineTable>[0]> = {}) {
    const props = {
        records: page(rows),
        lgas: [{ code: 'CHK', name: 'Chikun' }],
        selected: [] as number[],
        onSelectedChange: vi.fn(),
        correcting: null as number | null,
        onCorrectingChange: vi.fn(),
        onReject: vi.fn(),
        onCorrect: vi.fn(),
        onKeepExisting: vi.fn(),
        onUseThis: vi.fn(),
        onDetails: vi.fn(),
        onPage: vi.fn(),
        ...overrides,
    };
    renderUi(<QuarantineTable {...props} />, translations);

    return props;
}

describe('QuarantineTable', () => {
    it('lists each held record with its source, rules and round, and reports the row actions', async () => {
        const user = userEvent.setup();
        const props = quarantineTable([held(7)]);

        const table = screen.getByRole('table', { name: 'Quarantined records' });
        const row = within(table).getByRole('checkbox', { name: 'Select uuid:7' }).closest('tr') as HTMLElement;
        expect(row.textContent).toContain('KoboToolbox');
        expect(row.textContent).toContain('LGA_UNKNOWN');
        expect(row.textContent).toContain('Q2 2026');
        expect(within(row).queryByRole('button', { name: 'Keep the existing one' })).toBeNull();

        await user.click(within(row).getByRole('checkbox', { name: 'Select uuid:7' }));
        expect(props.onSelectedChange).toHaveBeenCalledWith([7]);
        await user.click(within(row).getByRole('button', { name: 'Reject' }));
        expect(props.onReject).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
        await user.click(within(row).getByRole('button', { name: 'Correct' }));
        expect(props.onCorrectingChange).toHaveBeenCalledWith(7);
        await user.click(within(row).getByRole('button', { name: 'Details' }));
        expect(props.onDetails).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
        expect(await axeCheck(document.body)).toHaveNoViolations();
    });

    it('offers both sides of a duplicate, and nothing to select or resolve on a resolved record', async () => {
        const user = userEvent.setup();
        const duplicate = held(8, 'DUPLICATE_ASSESSMENT', { duplicate_of: { assessment_id: 3, facility_id: 1, source: { key: 'odk', name: 'ODK Central' }, instance_id: 'uuid:3', url: null } });
        const resolved = held(9, 'LGA_UNKNOWN', { status: 'resolved', resolution: 'rejected', resolved_at: '2026-04-15T10:00:00+01:00' });
        const props = quarantineTable([duplicate, resolved]);

        const dup = screen.getByRole('checkbox', { name: 'Select uuid:8' }).closest('tr') as HTMLElement;
        await user.click(within(dup).getByRole('button', { name: 'Keep the existing one' }));
        expect(props.onKeepExisting).toHaveBeenCalledWith(expect.objectContaining({ id: 8 }));
        await user.click(within(dup).getByRole('button', { name: 'Use this one instead' }));
        expect(props.onUseThis).toHaveBeenCalledWith(expect.objectContaining({ id: 8 }));

        const done = screen.getByText('uuid:9').closest('tr') as HTMLElement;
        expect(within(done).queryByRole('checkbox')).toBeNull();
        expect(within(done).queryByRole('button', { name: 'Reject' })).toBeNull();
    });

    it('opens the correction form under the row being corrected', () => {
        quarantineTable([held(7)], { correcting: 7 });

        expect(screen.getByRole('button', { name: 'Correct' }).getAttribute('aria-expanded')).toBe('true');
        expect(screen.getAllByRole('row').length).toBeGreaterThan(2);
    });
});

describe('FlaggedTable', () => {
    it('lists each flagged assessment and reports which flag to override', async () => {
        const user = userEvent.setup();
        const onOverride = vi.fn();
        const flagged: FlaggedRow = { id: 5, facility: { id: 1, code: 'ZAR-0002', name: 'Tudun Wada PHC' }, lga: { code: 'ZAR', name: 'Zaria' }, round: { id: 1, year: 2026, quarter: 2 }, assessor: 'A. Bello', started_at: '2026-04-14T09:00:00+01:00', flags: [{ code: 'ALL_YES', name: 'Every answer yes' }], source: null };
        renderUi(<FlaggedTable records={page([flagged])} onOverride={onOverride} onPage={vi.fn()} />, translations);

        const row = within(screen.getByRole('table', { name: 'Flagged assessments' })).getByRole('row', { name: /Tudun Wada PHC/ });
        expect(row.textContent).toContain('Zaria');
        await user.click(within(row).getByRole('button', { name: 'Override Every answer yes' }));
        expect(onOverride).toHaveBeenCalledWith(flagged, 'ALL_YES', 'Every answer yes');
    });
});

describe('IssueFilters', () => {
    it('reports each filter change from page one, and clears them all', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        renderUi(
            <IssueFilters
                filters={filters}
                rules={[{ code: 'LGA_UNKNOWN', name: 'Unknown LGA', severity: 'hard', why: '', open: 1 }, { code: 'ALL_YES', name: 'Every answer yes', severity: 'soft', why: '', open: 0 }]}
                lgas={[{ code: 'ZAR', name: 'Zaria' }]}
                rounds={[{ id: 4, year: 2026, quarter: 2, status: 'open' }]}
                sources={[{ key: 'odk', name: 'ODK Central' }]}
                onChange={onChange}
            />,
            translations,
        );

        const rule = screen.getByRole('combobox', { name: 'Rule' });
        expect(within(rule).queryByRole('option', { name: 'Every answer yes' })).toBeNull(); // the Hard tab's rules only
        await user.selectOptions(rule, 'LGA_UNKNOWN');
        expect(onChange).toHaveBeenLastCalledWith({ rule: 'LGA_UNKNOWN', page: 1 });
        await user.selectOptions(screen.getByRole('combobox', { name: 'Round' }), '4');
        expect(onChange).toHaveBeenLastCalledWith({ round: 4, page: 1 });
        await user.selectOptions(screen.getByRole('combobox', { name: 'Source' }), 'odk');
        expect(onChange).toHaveBeenLastCalledWith({ source: 'odk', page: 1 });
        await user.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(onChange).toHaveBeenLastCalledWith({ state: 'open', rule: null, lga: null, round: null, source: null, page: 1 });
    });
});

describe('IssueBulkBar', () => {
    it('reports a bulk reject or correction when every selected record fails one rule', async () => {
        const user = userEvent.setup();
        const onReject = vi.fn();
        const onCorrect = vi.fn();
        renderUi(<IssueBulkBar count={2} allowed onReject={onReject} onCorrect={onCorrect} onClear={vi.fn()} />, translations);

        await user.click(screen.getByRole('button', { name: 'Reject selected' }));
        expect(onReject).toHaveBeenCalled();
        await user.selectOptions(screen.getByRole('combobox', { name: 'Field' }), 'round_year');
        await user.type(screen.getByRole('textbox', { name: 'New value' }), '2025');
        await user.click(screen.getByRole('button', { name: 'Correct selected' }));
        expect(onCorrect).toHaveBeenCalledWith('round_year', { round_year: 2025 });
    });

    it('says why bulk is off when the selection mixes rules', () => {
        renderUi(<IssueBulkBar count={2} allowed={false} onReject={vi.fn()} onCorrect={vi.fn()} onClear={vi.fn()} />, translations);

        const reject = screen.getByRole('button', { name: 'Reject selected' });
        expect((reject as HTMLButtonElement).disabled).toBe(true);
        expect(document.getElementById(reject.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Pick records failing one rule.');
    });
});
