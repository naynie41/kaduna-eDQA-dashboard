import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import Drawer from '@/components/Drawer';
import FacilityDetailView from '@/components/FacilityDetailView';
import HistorySparkline from '@/components/HistorySparkline';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { FacilityDetail } from '@/types/dashboard';
import type { ScoreValue } from '@/types/score';

const score = (display: string, band: ScoreValue['band'] = 'acceptable', bandLabel = 'Acceptable'): ScoreValue => ({
    value: Number(display),
    display,
    headline: display,
    band,
    bandLabel,
});

const translations = {
    ...testTranslations,
    enums: { dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    dashboard: {
        drawer: {
            close: 'Close',
            scores_title: 'Scores, :round',
            assessed: 'Assessed :date by :assessor.',
            overall: 'Overall',
            items_title: 'Item responses',
            results: { pass: 'Pass', fail: 'Fail', na: 'N/A', unknown: 'Not scored' },
            history_title: 'Overall by round',
            history_none: 'No scored rounds yet.',
            history_single: 'One scored round: :score in :round.',
            history_summary: ':count scored rounds, from :first in :first_round to :last in :last_round.',
            round: 'Round',
            shown: '(shown above)',
            flags_title: 'Flags',
            flag_open: 'Open',
            flag_overridden: 'Overridden',
            overridden_by: 'Overridden by :by, :date.',
            reason: 'Reason: :reason',
            attachments_title: 'Attachments',
            attachments_none: 'No attachments.',
            attachment_alt: 'Photo :filename from :facility, :round',
            attachment_open: 'Open :filename full size',
            attachment_download: 'Download :filename',
            attachment_status: { pending: 'Not fetched from ODK yet', failed: 'Could not be fetched from ODK; it will be tried again', rejected: 'Refused, not stored' },
            attachment_reasons: { type_not_allowed: 'its file type is not allowed' },
            activity_title: 'Changes',
            system: 'System',
            events: { updated: 'Updated', flag_overridden: 'Flag overridden' },
            on_assessment: 'Assessment',
            on_item: 'Item :code',
            change: ':field: :old → :new',
            empty_value: '(none)',
            details: 'Facility',
            code: 'Code',
            level: 'Level',
            owner: 'Owner',
            status: 'Status',
            active: 'Active',
            odk_title: 'ODK submission',
            instance: 'Instance ID',
            open_in_odk: 'Open in ODK Central',
            new_tab: '(opens in a new tab)',
            odk_not_configured: 'ODK Central is not connected yet, so there is no link.',
        },
    },
};

const detail: FacilityDetail = {
    facility: { id: 5, code: 'KD-F1', name: 'Facility F1', lga: 'Alpha', ward: 'Ward F1', level: 'primary', level_label: 'Primary', ownership: 'public', ownership_label: 'Public', active: true },
    round: { id: 2, label: 'Q2 2026', year: 2026, quarter: 2 },
    assessment: { id: 9, assessed_on: '2026-04-10', assessor: 'Assessor', status: 'flagged' },
    scores: { availability: score('90.0', 'strong', 'Strong'), consistency: score('80.0'), validity: score('70.0', 'review', 'Review'), overall: score('80.0') },
    items: [
        {
            dimension: 'availability',
            dimension_label: 'Availability',
            slot: 1,
            month: 'April 2026',
            score: score('50.0', 'needs_action', 'Needs action'),
            items: [
                { code: 'item_1', label: null, result: 'pass' },
                { code: 'item_2', label: 'Register in place', result: 'fail' },
                { code: 'item_3', label: null, result: 'na' },
            ],
        },
    ],
    history: [
        { round_id: 1, label: 'Q1 2026', overall: score('10.0', 'needs_action', 'Needs action'), selected: false },
        { round_id: 2, label: 'Q2 2026', overall: score('80.0'), selected: true },
    ],
    flags: [
        { code: 'SCORE_JUMP', label: 'Large score change', overridden: null },
        { code: 'ALL_PERFECT', label: 'Every score perfect', overridden: { by: 'Amina Bello', at: '2026-05-01T10:00:00+01:00', reason: 'Checked on site' } },
    ],
    attachments: [],
    activity: [{ id: 3, at: '2026-05-01T10:00:00+01:00', actor: null, event: 'updated', subject: 'item', item_code: 'item_2', changes: [{ field: 'value', old: 'no', new: 'yes' }], reason: null }],
    odk: { instance_id: 'uuid:abc', url: null },
};

function Harness() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <h1 tabIndex={-1}>Facility view</h1>
            <button type="button" onClick={() => setOpen(true)}>
                Facility F1
            </button>
            {open && (
                <Drawer title="Facility F1" subtitle="Alpha LGA" closeLabel="Close" onClose={() => setOpen(false)}>
                    <a href="#one">First link</a>
                    <button type="button">Last button</button>
                </Drawer>
            )}
        </>
    );
}

describe('Drawer', () => {
    it('is a modal dialog named by its heading, which takes focus', async () => {
        renderUi(<Harness />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Facility F1' }));

        const dialog = screen.getByRole('dialog', { name: 'Facility F1' });
        expect(dialog.getAttribute('aria-modal')).toBe('true');
        expect(document.activeElement).toBe(within(dialog).getByRole('heading', { name: 'Facility F1' }));
        expect(await axeCheck(document.body)).toHaveNoViolations();
    });

    it('keeps Tab inside, both ways', async () => {
        renderUi(<Harness />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Facility F1' }));

        await userEvent.tab(); // heading → Close
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close' }));
        await userEvent.tab();
        await userEvent.tab();
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Last button' }));
        await userEvent.tab(); // wraps
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close' }));
        await userEvent.tab({ shift: true }); // wraps back
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Last button' }));
    });

    it('closes on Esc and on Close, focus going back to the opener', async () => {
        renderUi(<Harness />, translations);
        const opener = screen.getByRole('button', { name: 'Facility F1' });

        await userEvent.click(opener);
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.activeElement).toBe(opener);

        await userEvent.click(opener);
        await userEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.activeElement).toBe(opener);
    });
});

describe('FacilityDetailView', () => {
    it('shows each item result as a word, never colour alone, and labels where known', async () => {
        const { container } = renderUi(<FacilityDetailView detail={detail} />, translations);

        const april = screen.getByRole('heading', { name: /April 2026/ }).parentElement as HTMLElement;
        expect(within(april).getAllByRole('listitem').map((li) => li.textContent)).toEqual(['item_1Pass', 'Register in placeFail', 'item_3N/A']);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('lists flags open and overridden, the changes, and the ODK reference', () => {
        renderUi(<FacilityDetailView detail={detail} />, translations);

        expect(screen.getByText('Large score change').nextElementSibling?.textContent).toBe('Open');
        expect(screen.getByText(/Overridden by Amina Bello, 1 May 2026/).textContent).toContain('Reason: Checked on site');
        expect(screen.getByText('value: no → yes')).toBeTruthy();
        expect(screen.getByText(/System/)).toBeTruthy();
        expect(screen.getByText('uuid:abc')).toBeTruthy();
        expect(screen.getByText('ODK Central is not connected yet, so there is no link.')).toBeTruthy();
        expect(screen.queryByRole('link', { name: /Open in ODK Central/ })).toBeNull();
    });

    it('links to ODK Central when configured, saying it opens a new tab', () => {
        renderUi(<FacilityDetailView detail={{ ...detail, odk: { instance_id: 'uuid:abc', url: 'https://central.example.org/#/x' } }} />, translations);

        expect(screen.getByRole('link', { name: 'Open in ODK Central (opens in a new tab)' }).getAttribute('href')).toBe('https://central.example.org/#/x');
    });
});

describe('HistorySparkline', () => {
    it('says where the line starts and ends and gives the same figures as a table', () => {
        renderUi(<HistorySparkline history={detail.history} />, translations);

        expect(screen.getByText('2 scored rounds, from 10.0 in Q1 2026 to 80.0 in Q2 2026.')).toBeTruthy();
        const table = screen.getByRole('table', { name: 'Overall by round' });
        expect(within(table).getAllByRole('rowheader').map((th) => th.textContent)).toEqual(['Q1 2026', 'Q2 2026 (shown above)']);
        expect(document.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
    });
});

describe('FacilityDetailView attachments', () => {
    const withAttachments: FacilityDetail = {
        ...detail,
        attachments: [
            { id: 1, filename: 'register.jpg', mime: 'image/jpeg', size: 2048, status: 'fetched', reason: null, url: '/attachments/7/register.jpg' },
            { id: 2, filename: 'trace.geojson', mime: 'application/geo+json', size: 120, status: 'fetched', reason: null, url: '/attachments/7/trace.geojson' },
            { id: 3, filename: 'later.png', mime: null, size: null, status: 'pending', reason: null, url: null },
            { id: 4, filename: 'evil.jpg', mime: null, size: null, status: 'rejected', reason: 'type_not_allowed', url: null },
        ],
    };

    it('shows a fetched image as a thumbnail whose alt text names the file, facility and round', async () => {
        const { container } = renderUi(<FacilityDetailView detail={withAttachments} />, translations);

        const image = screen.getByRole('img', { name: 'Photo register.jpg from Facility F1, Q2 2026' });
        expect(image.getAttribute('src')).toBe('/attachments/7/register.jpg');
        expect(screen.getByRole('link', { name: 'Open register.jpg full size' }).getAttribute('href')).toBe('/attachments/7/register.jpg');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('offers any other fetched file as a download, and says in words why a file is missing', () => {
        renderUi(<FacilityDetailView detail={withAttachments} />, translations);

        const download = screen.getByRole('link', { name: 'Download trace.geojson' });
        expect(download.getAttribute('href')).toBe('/attachments/7/trace.geojson');
        expect(download.hasAttribute('download')).toBe(true);
        expect(screen.getByText(/later\.png/).textContent).toBe('later.png · Not fetched from ODK yet');
        expect(screen.getByText(/evil\.jpg/).textContent).toBe('evil.jpg · Refused, not stored: its file type is not allowed');
        expect(screen.queryByRole('img', { name: /evil|later/ })).toBeNull();
    });
});