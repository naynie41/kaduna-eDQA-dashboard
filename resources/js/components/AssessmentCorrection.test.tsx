import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Drawer from '@/components/Drawer';
import FacilityDetailView from '@/components/FacilityDetailView';
import { correctionBody, draftCorrection, fromLocalInput, offsetOf, toLocalInput } from '@/lib/corrections';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { FacilityDetail } from '@/types/dashboard';
import type { ScoreValue } from '@/types/score';

const announce = vi.fn();
vi.mock('@/components/Announcer', async (original) => ({
    ...(await original<typeof import('@/components/Announcer')>()),
    useAnnounce: () => announce,
}));

const score = (display: string): ScoreValue => ({ value: Number(display), display, headline: display, band: 'acceptable', bandLabel: 'Acceptable' });

const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        reason: { label: 'Reason', hint: 'At least :min characters.', remaining: ':count more characters needed.' },
        ui: { ...((testTranslations.common as Record<string, Record<string, string>>).ui ?? {}), cancel: 'Cancel', working: 'Working…' },
    },
    enums: { dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    dashboard: {
        drawer: {
            scores_title: 'Scores, :round',
            assessed: 'Assessed :date by :assessor.',
            overall: 'Overall',
            items_title: 'Item responses',
            items_none: 'None.',
            results: { pass: 'Pass', fail: 'Fail', na: 'N/A', unknown: 'Not scored' },
            history_title: 'Overall by round',
            history_none: 'No scored rounds yet.',
            flags_title: 'Flags',
            flags_none: 'No flags.',
            attachments_title: 'Attachments',
            attachments_none: 'No attachments.',
            activity_title: 'Changes',
            activity_none: 'No changes.',
            details: 'Facility',
            code: 'Code',
            level: 'Level',
            owner: 'Owner',
            status: 'Status',
            active: 'Active',
            odk_title: 'ODK submission',
            odk_none: 'None.',
            correct: {
                start: 'Correct this assessment',
                title: 'Correcting this assessment',
                intro: 'Change item results or the visit times.',
                closed: 'Round :round is closed, so this assessment cannot be corrected.',
                item_label: ':item, :month',
                times: 'Visit times',
                started_at: 'Visit start',
                ended_at: 'Visit end',
                times_hint: 'Local time (:offset).',
                summary_none: 'No changes yet.',
                summary_item: '1 item changed: :list',
                summary_items: ':count items changed: :list',
                summary_times: 'Visit times: :old_start–:old_end → :new_start–:new_end',
                change: ':key :old → :new',
                review: 'Review and save',
                cancel: 'Cancel',
                dialog_title: 'Save this correction?',
                dialog_consequence: ':summary The scores are worked out again.',
                dialog_confirm: 'Save correction',
                saved: 'Correction saved.',
            },
        },
    },
};

const detail = (open = true): FacilityDetail => ({
    facility: { id: 5, code: 'KD-F1', name: 'Facility F1', lga: 'Alpha', ward: 'Ward F1', level: 'primary', level_label: 'Primary', ownership: 'public', ownership_label: 'Public', active: true },
    round: { id: 2, label: 'Q2 2026', year: 2026, quarter: 2, open },
    assessment: { id: 9, assessed_on: '2026-04-14', assessor: 'Assessor', status: 'accepted', started_at: '2026-04-14T09:00:00+01:00', ended_at: '2026-04-14T09:45:00+01:00' },
    scores: { availability: score('75.0'), consistency: score('75.0'), validity: score('75.0'), overall: score('75.0') },
    items: [
        {
            dimension: 'availability',
            dimension_label: 'Availability',
            slot: 2,
            month: 'May 2026',
            score: score('75.0'),
            items: [
                { code: 'register_present', key: 'avail_m2_register_present', label: 'Register in place', result: 'pass' },
                { code: 'reporting_rate', key: 'avail_m2_reporting_rate', label: null, result: 'fail' },
            ],
        },
    ],
    history: [],
    flags: [],
    attachments: [],
    activity: [],
    odk: { instance_id: null, url: null },
});

afterEach(() => {
    vi.unstubAllGlobals();
    announce.mockReset();
});

describe('the correction draft', () => {
    it('keeps only what changed, and never a score', () => {
        const d = detail();
        const draft = draftCorrection(d, { avail_m2_register_present: 'pass', avail_m2_reporting_rate: 'pass' }, '2026-04-14T09:00', '2026-04-14T10:00');

        expect(draft.changes).toEqual([{ key: 'avail_m2_reporting_rate', old: 'fail', new: 'pass' }]);
        expect(draft.startedAt).toBeNull();
        expect(draft.endedAt).toBe('2026-04-14T10:00:00+01:00');
        expect(correctionBody(draft, 'Supervisor re-checked')).toEqual({ items: { avail_m2_reporting_rate: 'pass' }, ended_at: '2026-04-14T10:00:00+01:00', reason: 'Supervisor re-checked' });
    });

    it('reads and writes local times with the original offset', () => {
        expect(toLocalInput('2026-04-14T09:00:00+01:00')).toBe('2026-04-14T09:00');
        expect(offsetOf('2026-04-14T09:00:00+01:00')).toBe('+01:00');
        expect(fromLocalInput('2026-04-14T09:30', '+01:00')).toBe('2026-04-14T09:30:00+01:00');
    });
});

describe('Correct this assessment', () => {
    it('makes the items editable, says what would change, and sends only that with the reason', async () => {
        const fetch = vi.fn(async () => new Response(JSON.stringify({ data: { assessment_id: 9 } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        vi.stubGlobal('fetch', fetch);
        const onCorrected = vi.fn();
        const { container } = renderUi(<FacilityDetailView detail={detail()} onCorrected={onCorrected} />, translations);
        const user = userEvent.setup();

        expect(screen.queryByRole('combobox')).toBeNull();
        await user.click(screen.getByRole('button', { name: 'Correct this assessment' }));

        const select = screen.getByRole('combobox', { name: 'reporting_rate, May 2026' });
        expect(screen.getByRole('combobox', { name: 'Register in place, May 2026' })).not.toBeNull();
        const review = screen.getByRole('button', { name: 'Review and save' });
        expect(review.hasAttribute('disabled')).toBe(true);
        expect(screen.getByRole('status').textContent).toBe('No changes yet.');

        await user.selectOptions(select, 'pass');
        expect(screen.getByRole('status').textContent).toBe('1 item changed: avail_m2_reporting_rate Fail → Pass');
        expect(await axeCheck(container)).toHaveNoViolations();

        await user.click(review);
        const dialog = screen.getByRole('dialog', { name: 'Save this correction?' });
        expect(dialog.textContent).toContain('1 item changed: avail_m2_reporting_rate Fail → Pass');
        await user.type(within(dialog).getByLabelText('Reason'), 'Supervisor re-checked the register');
        await user.click(within(dialog).getByRole('button', { name: 'Save correction' }));

        await waitFor(() => expect(onCorrected).toHaveBeenCalled());
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/api/assessments/9/corrections');
        expect(JSON.parse(String(init.body))).toEqual({ items: { avail_m2_reporting_rate: 'pass' }, reason: 'Supervisor re-checked the register' });
        expect(announce).toHaveBeenCalledWith('Correction saved.');
    });

    it('cancels back to the results as they were', async () => {
        renderUi(<FacilityDetailView detail={detail()} onCorrected={() => undefined} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Correct this assessment' }));
        await user.selectOptions(screen.getByRole('combobox', { name: 'reporting_rate, May 2026' }), 'na');
        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByRole('combobox')).toBeNull();
        expect(screen.getByText('Fail')).not.toBeNull();
    });

    it('is not offered in a closed round, and says why', () => {
        renderUi(<FacilityDetailView detail={detail(false)} onCorrected={() => undefined} />, translations);

        expect(screen.queryByRole('button', { name: 'Correct this assessment' })).toBeNull();
        expect(screen.getByText('Round Q2 2026 is closed, so this assessment cannot be corrected.')).not.toBeNull();
    });

    // Describes the behaviour; jsdom does not reproduce the Chromium bug it guards against
    // (tests/e2e/corrections.spec.ts does).
    it('inside the drawer, Esc in the reason dialog closes the dialog only', async () => {
        const onClose = vi.fn();
        renderUi(
            <Drawer title="Facility F1" closeLabel="Close" onClose={onClose}>
                <FacilityDetailView detail={detail()} onCorrected={() => undefined} />
            </Drawer>,
            translations,
        );
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Correct this assessment' }));
        await user.selectOptions(screen.getByRole('combobox', { name: 'reporting_rate, May 2026' }), 'pass');
        await user.click(screen.getByRole('button', { name: 'Review and save' }));
        await user.type(screen.getByLabelText('Reason'), 'abc');
        await user.keyboard('{Escape}');

        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Save this correction?' })).toBeNull());
        expect(onClose).not.toHaveBeenCalled();

        await user.keyboard('{Escape}');
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('is not offered where the view is shown without corrections', () => {
        renderUi(<FacilityDetailView detail={detail()} />, translations);

        expect(screen.queryByRole('button', { name: 'Correct this assessment' })).toBeNull();
    });
});
