import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import DeletedAtSourceTable from '@/components/DeletedAtSourceTable';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { DeletedAssessmentRow } from '@/types/admin';

// The Data issues page's Deleted at source tab (D-62): each assessment whose submission its source
// no longer returns, and what can be done with it.
const translations = {
    ...testTranslations,
    common: { ...(testTranslations.common as Record<string, unknown>), sources: { unknown: 'Unknown source' } },
    admin: {
        issues: {
            filters: { round_option: 'Q:quarter :year' },
            table: { showing: 'Showing :from–:to of :total', showing_none: 'Nothing to show.', pages: 'Pages' },
            actions: { open_in_source: 'Open in :source', new_tab: '(opens in a new tab)' },
            deleted: {
                caption: 'Assessments deleted at their source',
                none: 'No assessment has been deleted at its source.',
                facility: 'Facility',
                source: 'Source',
                instance: 'Instance ID',
                lga: 'LGA',
                round: 'Round',
                deleted: 'Deleted at source',
                assessment: 'Assessment',
                actions: 'Actions',
                statuses: { accepted: 'Counts (accepted)', flagged: 'Counts (flagged)', withdrawn: 'Withdrawn: counts nowhere' },
                reviewed: 'Reviewed by :name, :time',
                keep: 'Keep — the deletion was a mistake at source',
                keep_named: 'Keep the assessment of :facility',
                withdraw: 'Withdraw assessment',
                withdraw_named: 'Withdraw the assessment of :facility',
                restore: 'Restore',
                restore_named: 'Restore the assessment of :facility',
                closed: 'Round closed: reopen it to change this.',
            },
        },
    },
};

const row = (overrides: Partial<DeletedAssessmentRow> = {}): DeletedAssessmentRow => ({
    submission_id: 9,
    source: { key: 'kobo', name: 'KoboToolbox' },
    instance_id: 'uuid:gone-1',
    source_url: 'https://kf.example.org/api/v2/assets/aX/data/12/',
    deleted_at: '2026-10-15T02:00:00Z',
    assessment: { id: 77, status: 'accepted' },
    facility: { id: 31, code: 'KD-0031', name: 'Kujama PHC' },
    lga: 'Chikun',
    round: { id: 2, year: 2026, quarter: 4, open: true },
    reviewed: null,
    ...overrides,
});

const page = (rows: DeletedAssessmentRow[]) => ({ data: rows, current_page: 1, last_page: 1, from: rows.length === 0 ? null : 1, to: rows.length, total: rows.length });

describe('DeletedAtSourceTable', () => {
    it('lists each assessment deleted at source with its source, round and state, and offers Keep and Withdraw while unreviewed', async () => {
        const onKeep = vi.fn();
        const onWithdraw = vi.fn();
        const { container } = renderUi(<DeletedAtSourceTable records={page([row()])} onKeep={onKeep} onWithdraw={onWithdraw} onRestore={vi.fn()} onPage={vi.fn()} />, translations);
        const user = userEvent.setup();

        const table = screen.getByRole('table', { name: 'Assessments deleted at their source' });
        const line = within(table).getByRole('rowheader', { name: /Kujama PHC/ }).parentElement as HTMLElement;
        expect(line.textContent).toContain('KoboToolbox');
        expect(line.textContent).toContain('uuid:gone-1');
        expect(line.textContent).toContain('Q4 2026');
        expect(line.textContent).toContain('Counts (accepted)');
        expect(within(line).getByRole('link', { name: 'Open in KoboToolbox (opens in a new tab)' })).not.toBeNull();

        await user.click(within(line).getByRole('button', { name: 'Keep the assessment of Kujama PHC' }));
        expect(onKeep).toHaveBeenCalledWith(expect.objectContaining({ submission_id: 9 }));
        await user.click(within(line).getByRole('button', { name: 'Withdraw the assessment of Kujama PHC' }));
        expect(onWithdraw).toHaveBeenCalledWith(expect.objectContaining({ assessment: { id: 77, status: 'accepted' } }));
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('offers Restore for a withdrawn one, says who reviewed it, and no action in a closed round', () => {
        const onRestore = vi.fn();
        renderUi(
            <DeletedAtSourceTable
                records={page([
                    row({ assessment: { id: 78, status: 'withdrawn' }, reviewed: { at: '2026-10-16T09:00:00Z', by: 'Amina Bello' } }),
                    row({ submission_id: 10, instance_id: 'uuid:gone-2', facility: { id: 32, code: 'KD-0032', name: 'Zonkwa PHC' }, round: { id: 1, year: 2026, quarter: 3, open: false } }),
                ])}
                onKeep={vi.fn()}
                onWithdraw={vi.fn()}
                onRestore={onRestore}
                onPage={vi.fn()}
            />,
            translations,
        );

        const withdrawn = screen.getByRole('rowheader', { name: /Kujama PHC/ }).parentElement as HTMLElement;
        expect(withdrawn.textContent).toContain('Withdrawn: counts nowhere');
        expect(withdrawn.textContent).toContain('Reviewed by Amina Bello, 16 Oct 2026, 10:00 WAT');
        expect(within(withdrawn).queryByRole('button', { name: /Withdraw/ })).toBeNull();
        expect(within(withdrawn).getByRole('button', { name: 'Restore the assessment of Kujama PHC' })).not.toBeNull();

        const closed = screen.getByRole('rowheader', { name: /Zonkwa PHC/ }).parentElement as HTMLElement;
        expect(closed.textContent).toContain('Round closed: reopen it to change this.');
        expect(within(closed).queryByRole('button', { name: /Keep|Withdraw|Restore/ })).toBeNull();
    });

    it('says so when nothing was deleted at source', () => {
        renderUi(<DeletedAtSourceTable records={page([])} onKeep={vi.fn()} onWithdraw={vi.fn()} onRestore={vi.fn()} onPage={vi.fn()} />, translations);

        expect(screen.getByText('No assessment has been deleted at its source.')).not.toBeNull();
    });
});
