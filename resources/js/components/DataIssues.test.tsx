import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CorrectIssueForm from '@/components/CorrectIssueForm';
import DataIssueDrawer from '@/components/DataIssueDrawer';
import RuleSummary from '@/components/RuleSummary';
import { bulkRule } from '@/lib/dataIssues';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { DataIssueDetail, DataIssueRow, Failure } from '@/types/admin';

const translations = {
    ...testTranslations,
    admin: {
        issues: {
            summary: { title: 'Rules', description: 'Every rule.', open: ':count open', hard: 'Hard: quarantines', soft: 'Soft: flags' },
            correct: {
                title: 'Correct :instance',
                intro: 'Only these fields can be corrected.',
                fields: { lga_ref: 'LGA', ward_ref: 'Ward', facility_ref: 'Facility', raw_start: 'Visit start', raw_end: 'Visit end', round_year: 'Round year', round_quarter: 'Round quarter' },
                as_sent: 'As sent: :value',
                empty: '(empty)',
                lga_none: 'Leave empty',
                facility_search: 'Search facilities in this LGA',
                facility_pick: 'Facility (active facilities only)',
                facility_keep: 'Keep :value',
                facility_none: 'No active facility matches.',
                quarter_none: 'None',
                no_changes: 'Change at least one field first.',
                review: 'Review and confirm',
                cancel: 'Cancel',
            },
            drawer: {
                title: 'Submission :instance',
                close: 'Close',
                failures: 'Why it is held',
                parsed: 'What the portal read',
                payload: 'The submission as ODK Central sent it',
                payload_hint: 'Exactly as stored. It is never changed.',
                payload_show: 'Show the raw submission',
                corrections: 'Corrections so far',
                corrections_none: 'None.',
                history: 'History',
                history_none: 'Nothing recorded yet.',
                by: ':event by :by, :time',
                by_system: ':event, :time',
                reason: 'Reason: :reason',
                events: { created: 'Quarantined', corrected: 'Corrected', rejected: 'Rejected', superseded: 'Superseded', updated: 'Updated' },
                loading: 'Loading the record…',
            },
        },
    },
};

const failure = (code: string): Failure => ({ code, severity: 'hard', name: code, detail: `${code} detail`, field: null });

const row: DataIssueRow = {
    id: 7,
    instance_id: 'uuid:abc',
    submission_id: 70,
    failures: [failure('LGA_UNKNOWN')],
    facility_ref: 'CHK-0001',
    facility_name: 'Kujama PHC',
    lga_ref: null,
    round: { id: 1, year: 2026, quarter: 2 },
    received_at: '2026-04-14T10:00:00+01:00',
    status: 'open',
    resolution: null,
    resolution_note: null,
    resolved_at: null,
    corrections: {},
    current: { lga_ref: null, ward_ref: 'CHK-KUJ', facility_ref: 'CHK-0001', raw_start: '2026-04-14T09:00:00.000+01:00', raw_end: '2026-04-14T09:45:00.000+01:00', round_year: 2026, round_quarter: 2 },
    odk_url: null,
};

const lgas = [
    { code: 'CHK', name: 'Chikun' },
    { code: 'ZAR', name: 'Zaria' },
];

function stubFetch(body: unknown) {
    const fetch = vi.fn(async () => new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('bulkRule', () => {
    const open = (...codes: string[]) => ({ status: 'open' as const, failures: codes.map(failure) });

    it('allows bulk when every selected record fails the same single rule', () => {
        expect(bulkRule([open('ROUND_WINDOW'), open('ROUND_WINDOW')])).toBe('ROUND_WINDOW');
    });

    it('refuses mixed rules, a record failing two rules, a resolved record, and nothing selected', () => {
        expect(bulkRule([open('ROUND_WINDOW'), open('LGA_UNKNOWN')])).toBeNull();
        expect(bulkRule([open('LGA_UNKNOWN'), open('LGA_UNKNOWN', 'COLUMN_DRIFT')])).toBeNull();
        expect(bulkRule([open('LGA_UNKNOWN'), { status: 'resolved' as const, failures: [failure('LGA_UNKNOWN')] }])).toBeNull();
        expect(bulkRule([])).toBeNull();
    });
});

describe('CorrectIssueForm', () => {
    it('shows only the whitelisted fields, each with what was sent, and sends only what changed', async () => {
        stubFetch({ data: [] });
        const onReview = vi.fn();
        const { container } = renderUi(<CorrectIssueForm row={row} lgas={lgas} onReview={onReview} onCancel={() => undefined} />, translations);
        const user = userEvent.setup();

        const labels = Array.from(container.querySelectorAll('label, legend')).map((el) => el.textContent);
        expect(labels).toEqual(['LGA', 'Ward', 'Facility', 'Search facilities in this LGA', 'Facility (active facilities only)', 'Visit start', 'Visit end', 'Round year', 'Round quarter']);
        expect(screen.getByLabelText('Ward').getAttribute('aria-describedby')).not.toBeNull();
        expect(screen.getByText('As sent: CHK-KUJ')).not.toBeNull();

        await user.selectOptions(screen.getByLabelText('LGA'), 'CHK');
        await user.click(screen.getByRole('button', { name: 'Review and confirm' }));

        expect(onReview).toHaveBeenCalledWith({ lga_ref: 'CHK' });
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says to change a field first, and sends nothing, when nothing changed', async () => {
        stubFetch({ data: [] });
        const onReview = vi.fn();
        renderUi(<CorrectIssueForm row={row} lgas={lgas} onReview={onReview} onCancel={() => undefined} />, translations);

        await userEvent.setup().click(screen.getByRole('button', { name: 'Review and confirm' }));

        expect(onReview).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toBe('Change at least one field first.');
    });

    it('searches the chosen LGA\'s active facilities and sends the code picked, numbers as numbers', async () => {
        const fetch = stubFetch({ data: [{ code: 'CHK-0002', name: 'Narayi PHC', ward: 'Narayi' }] });
        const onReview = vi.fn();
        renderUi(<CorrectIssueForm row={row} lgas={lgas} onReview={onReview} onCancel={() => undefined} />, translations);
        const user = userEvent.setup();

        await user.selectOptions(screen.getByLabelText('LGA'), 'CHK');
        await screen.findByRole('option', { name: 'Narayi PHC (CHK-0002), Narayi' }, { timeout: 2000 });
        expect(fetch.mock.calls.some((call: unknown[]) => String(call[0]).includes('lga=CHK'))).toBe(true);

        await user.selectOptions(screen.getByLabelText('Facility (active facilities only)'), 'CHK-0002');
        await user.selectOptions(screen.getByLabelText('Round quarter'), '1');
        await user.click(screen.getByRole('button', { name: 'Review and confirm' }));

        expect(onReview).toHaveBeenCalledWith({ lga_ref: 'CHK', facility_ref: 'CHK-0002', round_quarter: 1 });
    });
});

describe('DataIssueDrawer', () => {
    const detail: DataIssueDetail = {
        ...row,
        parsed: { facility_ref: 'CHK-0001', items: [] },
        payload: { __id: 'uuid:abc', facility: { lga: null } },
        history: [{ id: 1, event: 'rejected', at: '2026-04-15T10:00:00+01:00', by: 'Amina Bello', reason: 'Training submission', old: {}, new: {} }],
    };

    it('keeps the raw payload collapsed and read-only, and lists failures and history', async () => {
        stubFetch({ data: detail });
        const { container } = renderUi(<DataIssueDrawer recordId={7} instanceId="uuid:abc" onClose={() => undefined} />, translations);

        await screen.findByText('LGA_UNKNOWN detail');
        const details = container.ownerDocument.querySelector('details');
        expect(details?.open).toBe(false);
        expect(details?.querySelector('pre')?.textContent).toContain('"__id": "uuid:abc"');
        expect(container.ownerDocument.querySelectorAll('details input, details textarea, [contenteditable="true"]')).toHaveLength(0);
        expect(screen.getByText('Reason: Training submission')).not.toBeNull();
        expect(screen.queryByText('items')).toBeNull();
    });
});

describe('RuleSummary', () => {
    it('is a set of toggle buttons that filter by a rule, with severity in words', async () => {
        const onChoose = vi.fn();
        const rules = [
            { code: 'LGA_UNKNOWN', severity: 'hard' as const, name: 'Unknown LGA', why: 'Every figure is by LGA.', open: 2 },
            { code: 'ROUND_WINDOW', severity: 'hard' as const, name: 'Outside the round', why: 'A visit belongs to one round.', open: 0 },
        ];
        const { container } = renderUi(<RuleSummary rules={rules} active="ROUND_WINDOW" onChoose={onChoose} />, translations);
        const user = userEvent.setup();

        const lga = screen.getByRole('button', { name: /Unknown LGA/ });
        expect(lga.getAttribute('aria-pressed')).toBe('false');
        expect(lga.textContent).toContain('Hard: quarantines');
        expect(lga.textContent).toContain('2 open');
        await user.click(lga);
        expect(onChoose).toHaveBeenCalledWith('LGA_UNKNOWN');

        await user.click(screen.getByRole('button', { name: /Outside the round/ }));
        expect(onChoose).toHaveBeenLastCalledWith(null);
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});
