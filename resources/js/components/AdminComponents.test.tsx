import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AdminRail from '@/components/AdminRail';
import ConfirmWithReasonDialog, { type ConfirmOutcome } from '@/components/ConfirmWithReasonDialog';
import ProgressPanel, { type ProgressStage } from '@/components/ProgressPanel';
import PublicImpactNote from '@/components/PublicImpactNote';
import SourceTag from '@/components/SourceTag';
import StaleIngestionBanner from '@/components/StaleIngestionBanner';
import { goTo } from '@/lib/navigate';
import { useApiAction } from '@/lib/useApiAction';
import { axeCheck, renderUi, testTranslations } from '@/test/render';

const announce = vi.fn();
vi.mock('@/components/Announcer', async (original) => ({
    ...(await original<typeof import('@/components/Announcer')>()),
    useAnnounce: () => announce,
}));
vi.mock('@/lib/navigate', () => ({ goTo: vi.fn() }));

const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        ui: { ...((testTranslations.common as Record<string, unknown>).ui as Record<string, string>), cancel: 'Cancel', working: 'Working…' },
        reason: { label: 'Reason', hint: 'At least :min characters.', remaining: ':count more characters needed.' },
        progress: { stage: ':label: :stage', failed: 'Could not get the progress. It will be tried again.' },
    },
};

/** The text an element is described by: its aria-describedby targets, in order. */
const description = (el: Element) =>
    (el.getAttribute('aria-describedby') ?? '')
        .split(' ')
        .filter((id) => id !== '')
        .map((id) => document.getElementById(id)?.textContent?.trim() ?? '')
        .join(' ');
const disabled = (el: HTMLElement) => (el as HTMLButtonElement).disabled;

function Harness({ onConfirm, onClose = () => undefined }: { onConfirm: (reason: string) => Promise<ConfirmOutcome>; onClose?: () => void }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Reject record
            </button>
            <ConfirmWithReasonDialog
                open={open}
                title="Reject this record?"
                consequence="It leaves quarantine for good and is never scored."
                confirmLabel="Reject"
                danger
                onConfirm={onConfirm}
                onClose={() => {
                    onClose();
                    setOpen(false);
                }}
            />
        </>
    );
}

describe('ConfirmWithReasonDialog', () => {
    it('opens named by its title and described by the consequence, with focus in the reason', async () => {
        const { container } = renderUi(<Harness onConfirm={async () => ({ ok: true })} />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Reject record' }));

        const dialog = screen.getByRole('dialog', { name: 'Reject this record?' });
        expect(description(dialog)).toBe('It leaves quarantine for good and is never scored.');
        const reason = screen.getByRole('textbox', { name: 'Reason' });
        expect(document.activeElement).toBe(reason);
        expect(description(reason)).toBe('At least 10 characters.');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('keeps the confirm button disabled until the trimmed reason has 10 characters', async () => {
        renderUi(<Harness onConfirm={async () => ({ ok: true })} />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Reject record' }));
        const confirm = screen.getByRole('button', { name: 'Reject' });
        const reason = screen.getByRole('textbox', { name: 'Reason' });

        expect(disabled(confirm)).toBe(true);
        await userEvent.type(reason, '   too short  '); // 9 characters once trimmed
        expect(disabled(confirm)).toBe(true);
        expect(screen.getByText(/1 more characters needed/)).toBeTruthy();
        await userEvent.type(reason, 'x');
        expect(disabled(confirm)).toBe(false);
    });

    it('sends the trimmed reason, closes, and gives focus back to the trigger', async () => {
        const onConfirm = vi.fn(async () => ({ ok: true }) as const);
        renderUi(<Harness onConfirm={onConfirm} />, translations);
        const trigger = screen.getByRole('button', { name: 'Reject record' });
        await userEvent.click(trigger);
        await userEvent.type(screen.getByRole('textbox', { name: 'Reason' }), '  Duplicate of an earlier visit  ');
        await userEvent.click(screen.getByRole('button', { name: 'Reject' }));

        expect(onConfirm).toHaveBeenCalledWith('Duplicate of an earlier visit');
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(document.activeElement).toBe(trigger);
    });

    it('closes with Esc and with Cancel, sending nothing, and focus goes back', async () => {
        const onConfirm = vi.fn(async () => ({ ok: true }) as const);
        const onClose = vi.fn();
        renderUi(<Harness onConfirm={onConfirm} onClose={onClose} />, translations);
        const trigger = screen.getByRole('button', { name: 'Reject record' });

        await userEvent.click(trigger);
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.activeElement).toBe(trigger);

        await userEvent.click(trigger);
        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog')).toBeNull();
        expect(document.activeElement).toBe(trigger);
        expect(onClose).toHaveBeenCalledTimes(2);
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('keeps Tab inside the dialog, both ways', async () => {
        renderUi(<Harness onConfirm={async () => ({ ok: true })} />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Reject record' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Reason' }), 'A long enough reason');
        const reason = screen.getByRole('textbox', { name: 'Reason' });
        const confirm = screen.getByRole('button', { name: 'Reject' });

        await userEvent.tab(); // reason → Cancel
        await userEvent.tab(); // → Reject, the last
        expect(document.activeElement).toBe(confirm);
        await userEvent.tab(); // wraps to the first
        expect(document.activeElement).toBe(reason);
        await userEvent.tab({ shift: true }); // and back to the last
        expect(document.activeElement).toBe(confirm);
    });

    it('announces a server refusal, stays open with the reason kept, and links a field error', async () => {
        const onConfirm = vi.fn(async (): Promise<ConfirmOutcome> => ({ ok: false, message: 'This record is already resolved.', fieldError: 'The reason must be at least 10 characters.' }));
        const { container } = renderUi(<Harness onConfirm={onConfirm} />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Reject record' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Reason' }), 'A long enough reason');
        await userEvent.click(screen.getByRole('button', { name: 'Reject' }));

        expect((await screen.findByRole('alert')).textContent).toContain('This record is already resolved.');
        const reason = screen.getByRole('textbox', { name: 'Reason' });
        expect((reason as HTMLTextAreaElement).value).toBe('A long enough reason');
        expect(reason.getAttribute('aria-invalid')).toBe('true');
        expect(description(reason)).toBe('At least 10 characters. The reason must be at least 10 characters.');
        expect(screen.getByRole('dialog')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

type Run = { status: string; fetched: number };

const stageOf = (run: Run): ProgressStage => ({
    key: run.status,
    label: run.status === 'succeeded' ? 'Done' : run.status === 'running' ? 'Fetching' : 'Queued',
    terminal: run.status === 'succeeded' || run.status === 'failed',
});

/** fetch answering each poll with the next run in the list (the last one repeats). */
function fetchRuns(runs: (Run | Error)[]) {
    let call = 0;
    const fetch = vi.fn(async () => {
        const next = runs[Math.min(call, runs.length - 1)];
        call += 1;
        if (next instanceof Error) {
            throw next;
        }

        return new Response(JSON.stringify(next), { status: 200, headers: { 'Content-Type': 'application/json' } });
    });
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

describe('ProgressPanel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        announce.mockClear();
    });
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    const panel = (onDone?: (run: Run) => void) => (
        <ProgressPanel<Run> title="Pull" url="/api/pulls/7" stage={stageOf} {...(onDone === undefined ? {} : { onDone })}>
            {(run) => <span>Fetched {run.fetched}</span>}
        </ProgressPanel>
    );

    it('polls with backoff from 2 s, and stops at the terminal state', async () => {
        const runs: Run[] = [
            { status: 'queued', fetched: 0 },
            { status: 'running', fetched: 100 },
            { status: 'running', fetched: 300 },
            { status: 'running', fetched: 400 },
            { status: 'succeeded', fetched: 489 },
        ];
        const fetch = fetchRuns(runs);
        const onDone = vi.fn();
        renderUi(panel(onDone), translations);
        await act(async () => vi.advanceTimersByTimeAsync(0));
        expect(fetch).toHaveBeenCalledTimes(1);

        await act(async () => vi.advanceTimersByTimeAsync(1999));
        expect(fetch).toHaveBeenCalledTimes(1);
        await act(async () => vi.advanceTimersByTimeAsync(1)); // 2 s after a new stage
        expect(fetch).toHaveBeenCalledTimes(2);
        await act(async () => vi.advanceTimersByTimeAsync(2000)); // stage changed: 2 s again
        expect(fetch).toHaveBeenCalledTimes(3);
        await act(async () => vi.advanceTimersByTimeAsync(2999)); // same stage: backs off to 3 s
        expect(fetch).toHaveBeenCalledTimes(3);
        await act(async () => vi.advanceTimersByTimeAsync(1));
        expect(fetch).toHaveBeenCalledTimes(4);
        await act(async () => vi.advanceTimersByTimeAsync(5000)); // then 5 s
        expect(fetch).toHaveBeenCalledTimes(5);
        expect(screen.getByText('Done')).toBeTruthy();
        expect(screen.getByText('Fetched 489')).toBeTruthy();
        expect(onDone).toHaveBeenCalledWith({ status: 'succeeded', fetched: 489 });

        await act(async () => vi.advanceTimersByTimeAsync(60_000)); // terminal: no more polls
        expect(fetch).toHaveBeenCalledTimes(5);
    });

    it('never waits more than 10 s between polls', async () => {
        const fetch = fetchRuns([{ status: 'running', fetched: 1 }]);
        renderUi(panel(), translations);
        await act(async () => vi.advanceTimersByTimeAsync(0));
        await act(async () => vi.advanceTimersByTimeAsync(2000 + 3000 + 5000 + 8000)); // 2, 3, 5, 8 s
        const before = fetch.mock.calls.length;
        await act(async () => vi.advanceTimersByTimeAsync(10_000));
        await act(async () => vi.advanceTimersByTimeAsync(10_000));
        expect(fetch.mock.calls.length).toBe(before + 2);
    });

    it('announces each change of stage only, never a tick of the counters', async () => {
        fetchRuns([
            { status: 'queued', fetched: 0 },
            { status: 'running', fetched: 100 },
            { status: 'running', fetched: 200 },
            { status: 'running', fetched: 300 },
            { status: 'succeeded', fetched: 489 },
        ]);
        renderUi(panel(), translations);
        await act(async () => vi.advanceTimersByTimeAsync(60_000));

        expect(announce.mock.calls.map(([message]) => message)).toEqual(['Pull: Queued', 'Pull: Fetching', 'Pull: Done']);
    });

    it('says when a poll fails, and carries on', async () => {
        const fetch = fetchRuns([new Error('offline'), { status: 'succeeded', fetched: 10 }]);
        renderUi(panel(), translations);
        await act(async () => vi.advanceTimersByTimeAsync(0));
        expect(screen.getByText('Could not get the progress. It will be tried again.')).toBeTruthy();

        await act(async () => vi.advanceTimersByTimeAsync(3000));
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(screen.queryByText(/Could not get the progress/)).toBeNull();
        expect(screen.getByText('Done')).toBeTruthy();
    });

    it('stops polling when it leaves the page', async () => {
        const fetch = fetchRuns([{ status: 'running', fetched: 1 }]);
        const { unmount, container } = renderUi(panel(), translations);
        await act(async () => vi.advanceTimersByTimeAsync(0));
        vi.useRealTimers();
        expect(await axeCheck(container)).toHaveNoViolations();
        vi.useFakeTimers();
        unmount();

        await act(async () => vi.advanceTimersByTimeAsync(60_000));
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});

const shellTranslations = {
    ...translations,
    admin: {
        tabs: { label: 'Administration pages', data_issues_open: ':label, :count open' },
        rail: { view_public: 'View public dashboard', account: 'My account', log_out: 'Log out' },
        public_impact: { label: 'Public dashboard:', text: 'this changes what the public dashboard shows.' },
        pages: {
            sources: { title: 'Data sources' },
            data_issues: { title: 'Data issues' },
            rounds: { title: 'Rounds' },
            facilities: { title: 'Facility list' },
            scoring_rules: { title: 'Scoring rules' },
            audit: { title: 'Audit log' },
        },
        stale: {
            title: 'No successful pull for over :hours hours',
            lead: 'Round Q:quarter :year is open, and new submissions from these sources are not reaching the dashboard:',
            source: ':source: last successful pull at :time.',
            source_never: ':source: no pull has succeeded yet.',
            link: 'Go to the Data sources page',
        },
    },
};

describe('AdminRail', () => {
    it('lists the six pages, My account and the public dashboard, then Log out; the current one marked, the open count in the Data issues name', async () => {
        const { container } = renderUi(<AdminRail current="rounds" openIssueCount={12} assessments={2184} />, shellTranslations);

        const nav = screen.getByRole('navigation', { name: 'Administration pages' });
        expect(within(nav).getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual([
            '/admin/sources', '/admin/data-issues', '/admin/rounds', '/admin/facilities', '/admin/scoring-rules', '/admin/audit',
            '/admin/settings/security', '/',
        ]);
        expect(within(nav).getByRole('link', { name: 'Audit log' })).toBeTruthy();
        expect(within(nav).getByRole('link', { name: 'My account' })).toBeTruthy();
        expect(within(nav).getByRole('link', { name: 'View public dashboard' })).toBeTruthy();
        expect(within(nav).getByRole('button', { name: 'Log out' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Data issues, 12 open' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Rounds' }).getAttribute('aria-current')).toBe('page');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('marks My account as the current page on the security settings', () => {
        renderUi(<AdminRail current="account" openIssueCount={0} assessments={null} />, shellTranslations);

        expect(screen.getByRole('link', { name: 'My account' }).getAttribute('aria-current')).toBe('page');
        expect(screen.getByRole('link', { name: 'Rounds' }).getAttribute('aria-current')).toBeNull();
    });
});

describe('PublicImpactNote', () => {
    it('says in words, with a mark, that the action changes the public dashboard', async () => {
        const { container } = renderUi(<PublicImpactNote />, shellTranslations);

        expect(container.textContent).toBe('Public dashboard: this changes what the public dashboard shows.');
        // The mark is decoration: the words carry the meaning.
        expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('takes a more specific line when the action needs one', () => {
        const { container } = renderUi(<PublicImpactNote>Closing Q3 2026 publishes its figures.</PublicImpactNote>, shellTranslations);

        expect(container.textContent).toBe('Public dashboard: Closing Q3 2026 publishes its figures.');
    });

    it('is read with the dialog it sits in, after the consequence', async () => {
        const { container } = renderUi(
            <ConfirmWithReasonDialog
                open
                title="Close Q3 2026?"
                consequence="Its scores are fixed."
                confirmLabel="Close round"
                publicImpact
                onConfirm={async () => ({ ok: true })}
                onClose={() => undefined}
            />,
            shellTranslations,
        );

        const dialog = screen.getByRole('dialog', { name: 'Close Q3 2026?' });
        expect(description(dialog)).toBe('Its scores are fixed. Public dashboard: this changes what the public dashboard shows.');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('is left out of a dialog whose action the public never sees', () => {
        renderUi(<Harness onConfirm={async () => ({ ok: true })} />, shellTranslations);

        expect(screen.queryByText(/public dashboard shows/)).toBeNull();
    });
});

describe('useApiAction', () => {
    function Probe({ url }: { url: string }) {
        const action = useApiAction();

        return (
            <>
                <button type="button" onClick={() => void action.run('POST', url)} disabled={action.pending}>
                    Send
                </button>
                {action.error !== null && <p role="alert">{action.error}</p>}
            </>
        );
    }

    const answer = (status: number, message: string) =>
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message }), { status, headers: { 'Content-Type': 'application/json' } })));

    beforeEach(() => vi.mocked(goTo).mockClear());
    afterEach(() => vi.unstubAllGlobals());

    it.each([401, 419])('sends the administrator to /login saying the session expired, on %i', async (status) => {
        answer(status, 'Unauthenticated.');
        renderUi(<Probe url="/admin/api/rounds/1/close" />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Send' }));

        await waitFor(() => expect(goTo).toHaveBeenCalledWith('/login?expired=1'));
    });

    it('keeps a 403 on the page, as a refusal, without leaving it', async () => {
        answer(403, 'Forbidden');
        renderUi(<Probe url="/admin/api/rounds/1/close" />, { ...translations, common: { ...translations.common, api: { forbidden: 'You cannot do this.' } } });

        await userEvent.click(screen.getByRole('button', { name: 'Send' }));

        expect((await screen.findByRole('alert')).textContent).toBe('You cannot do this.');
        expect(goTo).not.toHaveBeenCalled();
    });
});

describe('StaleIngestionBanner', () => {
    it('says which round is open, names each source that is behind with its last success, and links to the Data sources page', async () => {
        const { container } = renderUi(
            <StaleIngestionBanner
                stale={{
                    stale: true,
                    hours: 24,
                    round: { year: 2026, quarter: 4 },
                    sources: [
                        { key: 'odk', name: 'ODK Central', lastSuccessAt: null },
                        { key: 'kobo', name: 'KoboToolbox', lastSuccessAt: '2026-10-29T08:05:00+01:00' },
                    ],
                }}
            />,
            shellTranslations,
        );

        const banner = screen.getByRole('region', { name: 'No successful pull for over 24 hours' });
        expect(banner.textContent).toContain('Round Q4 2026 is open, and new submissions from these sources are not reaching the dashboard:');
        expect(within(banner).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
            'ODK Central: no pull has succeeded yet.',
            'KoboToolbox: last successful pull at 29 Oct 2026, 08:05 WAT.',
        ]);
        expect(within(banner).getByRole('link', { name: 'Go to the Data sources page' }).getAttribute('href')).toBe('/admin/sources');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('renders nothing while ingestion is current', () => {
        const { container } = renderUi(<StaleIngestionBanner stale={{ stale: false, hours: 24, round: { year: 2026, quarter: 4 }, sources: [] }} />, shellTranslations);

        expect(container.textContent).toBe('');
    });
});

describe('SourceTag', () => {
    it('names the source in words, never by colour alone', async () => {
        const { container } = renderUi(
            <p>
                <SourceTag source={{ key: 'odk', name: 'ODK Central' }} /> <SourceTag source={{ key: 'kobo', name: 'KoboToolbox' }} />
            </p>,
            shellTranslations,
        );

        expect(screen.getByText('ODK Central')).toBeTruthy();
        expect(screen.getByText('KoboToolbox')).toBeTruthy();
        // The two tags differ in their words, which a screen reader and a colour-blind reader both get.
        expect(container.textContent).toBe('ODK Central KoboToolbox');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says the source is unknown rather than showing nothing', () => {
        renderUi(<SourceTag source={null} />, { ...shellTranslations, common: { ...(shellTranslations.common as Record<string, unknown>), sources: { unknown: 'Unknown source' } } });

        expect(screen.getByText('Unknown source')).toBeTruthy();
    });
});
