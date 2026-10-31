import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import DeletionsPanel from '@/components/DeletionsPanel';
import DuplicateSides from '@/components/DuplicateSides';
import PullAllPanel from '@/components/PullAllPanel';
import PullHistoryPanel from '@/components/PullHistoryPanel';
import SourcePanel from '@/components/SourcePanel';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { DataIssueRow, DeletionRow, PullHistoryRow, SourceEntry } from '@/types/admin';

const routerGet = vi.fn();
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, get: (...args: unknown[]) => routerGet(...args) } };
});

// The keys these components read, as lang/en/admin.php has them.
const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many requests.' },
        progress: { stage: ':label: :stage', failed: 'Could not get the progress.' },
    },
    enums: { pull_trigger: { scheduled: 'Scheduled', manual: 'Pull now', webhook: 'Webhook', backfill: 'Backfill', reconcile: 'Reconcile' }, submission_status: { accepted: 'Accepted', flagged: 'Flagged' } },
    admin: {
        sources: {
            panel: {
                title: ':source',
                not_configured: 'Not configured',
                not_configured_body: ':source is not configured. Missing:',
                disabled: 'Switched off',
                disabled_body: ':source is configured but switched off.',
                runbook: 'The runbook',
                runbook_file: 'The runbook file',
                reachable: 'Connected',
                reachable_at: ':source answered at :time.',
                unreachable: 'Not reachable',
                unreachable_body: ':source did not answer at :time: :message',
                last_success: 'Last successful pull',
                never: 'Never',
                never_inline: 'never',
                ago: { minutes: ':count minutes ago', hours: ':count hours ago', days: ':count days ago' },
                last_success_value: ':ago (:time)',
                last_pull: 'Fetched in the last pull',
                last_pull_value: ':fetched fetched, :cross from another source',
                no_pull: 'No pull yet.',
                open_round: 'Q:quarter :year (open), so far',
                open_round_value: ':accepted accepted, :flagged flagged, :quarantined in quarantine',
                no_open_round: 'No round is open.',
                open_rounds: 'Open rounds',
                cursor: 'Where the pull stands',
                cursor_value: 'Up to :pulled; :count stored.',
                cursor_none: 'Not started.',
                reconciled: 'Last reconcile',
                reconciled_never: 'Not yet.',
                not_yet: 'none yet',
                last_error: 'Last error',
                error_status: ':source returned HTTP :status at :time; last success :success.',
                error_class: ':what at :time; last success :success.',
                errors: { auth: ":source refused the portal's credentials", unreachable: ':source could not be reached', unexpected: ':source sent an odd answer', QueryException: 'The database refused a write', other: 'The pull stopped' },
                error_detail: 'Detail: :error',
            },
            pull: {
                button: 'Pull now',
                starting: 'Starting…',
                attached: 'A pull from :source was already running; this follows it.',
                progress: 'Pull from :source',
                stages: { running: 'Pulling', succeeded: 'Finished', failed: 'Failed', skipped_not_configured: 'Skipped' },
                counters: 'Progress so far',
                fetched: 'Fetched',
                parsed: 'Parsed',
                accepted: 'Accepted',
                flagged: 'Flagged',
                quarantined: 'Quarantined',
                duplicates: 'Already stored',
                cross_source_duplicates: 'From another source',
                deletions_detected: 'Deleted at source',
            },
            reconcile: { button: 'Reconcile', description: 'Compares the open round with the source.', starting: 'Starting…', attached: 'Already running.', progress: 'Reconcile :source' },
            pull_all: { title: 'Pull all', description: 'Every source switched on.', button: 'Pull all', starting: 'Starting…', started: 'Pulls started: :sources.', joined: ':source (already running)' },
            history: {
                title: 'Pull history',
                caption: 'Every pull.',
                started: 'Started',
                source: 'Source',
                trigger: 'Trigger',
                by: 'Triggered by',
                retrieved: 'Retrieved',
                accepted: 'Accepted',
                flagged: 'Flagged',
                quarantined: 'Quarantined',
                duplicates: 'Already stored',
                cross_source_duplicates: 'From another source',
                duration: 'Duration',
                duration_value: ':minutes min :seconds s',
                duration_seconds: ':seconds s',
                outcome: 'Outcome',
                system: 'System',
                unknown_source: 'Unknown source',
                filter_source: 'Source',
                filter_trigger: 'Trigger',
                filter_outcome: 'Outcome',
                all_sources: 'All sources',
                all_triggers: 'All triggers',
                all_outcomes: 'All outcomes',
                outcomes: { succeeded: 'Succeeded', failed: 'Failed', skipped_not_configured: 'Skipped', running: 'Running' },
                none: 'No pull matches.',
                showing: 'Showing :from–:to of :total pulls',
                showing_none: 'No pulls yet.',
                pages: 'Pull history pages',
                filtered: 'Showing :total pulls.',
            },
            deletions: {
                title: 'Deletions at source',
                description: 'Submissions a source no longer returns.',
                caption: 'Deleted at their source.',
                none: 'No submission has been deleted at its source.',
                detected: 'Noticed',
                source: 'Source',
                instance: 'Instance ID',
                facility: 'Facility',
                round: 'Round',
                round_value: 'Q:quarter :year',
                in_portal: 'In the portal',
                assessment: 'Assessment (:status)',
                quarantined: 'In quarantine',
                nothing: 'Nothing built from it',
                open_assessment: 'Open :facility',
                open_issues: 'Open Data issues',
                open_in_source: 'Check in :source',
                new_tab: '(opens in a new tab)',
                more: 'Showing the newest :shown of :total.',
            },
        },
        issues: {
            table: { source_unknown: 'Unknown source' },
            duplicate: { label: 'Both sides of the duplicate', accepted: 'Already accepted', this: 'This submission', side: 'from :source,' },
            actions: { open_in_source: 'Open in :source', new_tab: '(opens in a new tab)' },
        },
    },
};

const sync = { lastSuccessAt: null, minutesSinceSuccess: null, sinceSuccess: null, lastFailedAt: null, lastError: null, lastErrorClass: null, lastErrorHttpStatus: null, staleAfterMinutes: 30 };

const source = (overrides: Partial<SourceEntry> = {}): SourceEntry => ({
    key: 'kobo',
    name: 'KoboToolbox',
    type: 'kobo',
    configured: true,
    problems: [],
    enabled: true,
    reachable: { ok: true, status: 'ok', message: null, checked_at: '2026-10-15T08:00:00Z' },
    cursor: null,
    last_reconciled_at: null,
    running_run_id: null,
    running_reconcile_run_id: null,
    last_run: { id: 3, outcome: 'succeeded', finished_at: '2026-10-15T07:50:00Z', fetched: 12, parsed: 12, accepted: 9, flagged: 1, quarantined: 1, duplicates: 0, cross_source_duplicates: 1 },
    status: { key: 'kobo', name: 'KoboToolbox', state: 'healthy', ...sync, lastSuccessAt: '2026-10-15T07:50:00Z', minutesSinceSuccess: 10, sinceSuccess: { value: 10, unit: 'minutes' } },
    open_rounds: [{ id: 2, year: 2026, quarter: 4, accepted: 9, flagged: 1, quarantined: 1 }],
    ...overrides,
});

const panel = (entry: SourceEntry, extra: Partial<Parameters<typeof SourcePanel>[0]> = {}) => (
    <SourcePanel source={entry} runbookUrl={null} pull={null} reconcile={null} onPull={() => undefined} onReconcile={() => undefined} onFinished={() => undefined} {...extra} />
);

function stubFetch(handler: (url: string, init?: RequestInit) => { status?: number; body: unknown }) {
    const fetch = vi.fn(async (url: string, init?: RequestInit) => {
        const { status = 200, body } = handler(url, init);

        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    });
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => {
    vi.unstubAllGlobals();
    routerGet.mockReset();
});

describe('SourcePanel', () => {
    it('is named by its source, and lists the missing settings when it is not configured, with no actions', async () => {
        const { container } = renderUi(panel(source({ configured: false, problems: ['KOBO_API_TOKEN', 'KOBO_ASSET_UID'], reachable: null })), translations);

        const region = screen.getByRole('region', { name: 'KoboToolbox' });
        expect(region.textContent).toContain('Not configured');
        expect(within(region).getByText('KOBO_API_TOKEN')).not.toBeNull();
        expect(within(region).queryByRole('button')).toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says a switched-off source is off, and offers neither Pull now nor Reconcile', () => {
        renderUi(panel(source({ enabled: false })), translations);

        expect(screen.getByText('Switched off')).not.toBeNull();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('shows reachability, the last success, the last pull and this source\'s open-round counts', async () => {
        const { container } = renderUi(panel(source()), translations);

        const region = screen.getByRole('region', { name: 'KoboToolbox' });
        expect(region.textContent).toContain('Connected');
        expect(region.textContent).toContain('10 minutes ago');
        expect(region.textContent).toContain('12 fetched, 1 from another source');
        expect(region.textContent).toContain('9 accepted, 1 flagged, 1 in quarantine');
        expect(screen.getByRole('button', { name: 'Pull now KoboToolbox' })).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Reconcile KoboToolbox' })).not.toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it.each([
        [{ lastErrorHttpStatus: 503, lastErrorClass: 'KoboUnexpectedResponse' }, 'KoboToolbox returned HTTP 503 at'],
        [{ lastErrorHttpStatus: null, lastErrorClass: 'KoboAuthFailed' }, "KoboToolbox refused the portal's credentials at"],
        [{ lastErrorHttpStatus: null, lastErrorClass: 'OdkUnreachable' }, 'KoboToolbox could not be reached at'],
        [{ lastErrorHttpStatus: null, lastErrorClass: 'SomethingElse' }, 'The pull stopped at'],
    ] as const)('says the last error in plain language: %o', (error, text) => {
        renderUi(panel(source({ status: { key: 'kobo', name: 'KoboToolbox', state: 'failed', ...sync, lastFailedAt: '2026-10-15T08:05:00Z', ...error } })), translations);

        expect(screen.getByText('Last error').parentElement?.textContent).toContain(text);
        expect(screen.getByText('Last error').parentElement?.textContent).toContain('last success never');
    });

    it('starts a pull on its own source and hands the run to the page', async () => {
        const fetch = stubFetch(() => ({ status: 202, body: { run_id: 41, already_running: false } }));
        const onPull = vi.fn();
        renderUi(panel(source(), { onPull }), translations);

        await userEvent.setup().click(screen.getByRole('button', { name: 'Pull now KoboToolbox' }));

        await waitFor(() => expect(onPull).toHaveBeenCalledWith({ id: 41, attached: false }));
        expect(fetch.mock.calls[0]?.[0]).toBe('/api/sources/kobo/pull');
    });

    it('follows the run it is given in a progress panel named by the source, the button disabled meanwhile', async () => {
        stubFetch(() => ({ body: { data: { run_id: 41, source: 'kobo', status: 'running', trigger: 'manual', started_at: '2026-10-15T08:00:00Z', finished_at: null, fetched: 4, parsed: 4, accepted: 3, flagged: 0, quarantined: 1, duplicates: 0, cross_source_duplicates: 2, deletions_detected: 0, error: null } } }));
        renderUi(panel(source(), { pull: { id: 41, attached: true } }), translations);

        const progress = await screen.findByRole('region', { name: 'Pull from KoboToolbox' });
        await waitFor(() => expect(within(progress).getByText('From another source').nextSibling?.textContent).toBe('2'));
        expect((screen.getByRole('button', { name: 'Pull now KoboToolbox' }) as HTMLButtonElement).disabled).toBe(true);
        expect(screen.getByText('A pull from KoboToolbox was already running; this follows it.')).not.toBeNull();
    });
});

describe('PullAllPanel', () => {
    it('starts every source\'s pull, says which, and hands the runs to the page', async () => {
        stubFetch(() => ({ status: 202, body: { runs: [{ source: 'odk', run_id: 5, already_running: true }, { source: 'kobo', run_id: 6, already_running: false }] } }));
        const onStarted = vi.fn();
        const { container } = renderUi(<PullAllPanel names={{ odk: 'ODK Central', kobo: 'KoboToolbox' }} onStarted={onStarted} />, translations);

        await userEvent.setup().click(screen.getByRole('button', { name: 'Pull all' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Pulls started: ODK Central (already running), KoboToolbox.'));
        expect(onStarted).toHaveBeenCalledWith([
            { source: 'odk', run_id: 5, already_running: true },
            { source: 'kobo', run_id: 6, already_running: false },
        ]);
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says the refusal in an alert when no source is switched on', async () => {
        stubFetch(() => ({ status: 409, body: { code: 'no_source_enabled', message: 'No source is switched on.' } }));
        const onStarted = vi.fn();
        renderUi(<PullAllPanel names={{}} onStarted={onStarted} />, translations);

        await userEvent.setup().click(screen.getByRole('button', { name: 'Pull all' }));

        expect((await screen.findByRole('alert')).textContent).toBe('No source is switched on.');
        expect(onStarted).not.toHaveBeenCalled();
    });
});

describe('PullHistoryPanel', () => {
    const row = (overrides: Partial<PullHistoryRow>): PullHistoryRow => ({
        id: 1,
        source: { key: 'odk', name: 'ODK Central' },
        started_at: '2026-10-15T08:00:00Z',
        trigger: 'manual',
        triggered_by: 'Amina Bello',
        fetched: 10,
        parsed: 10,
        accepted: 8,
        flagged: 1,
        quarantined: 1,
        duplicates: 2,
        cross_source_duplicates: 3,
        deletions_detected: 0,
        duration: { minutes: 0, seconds: 12 },
        outcome: 'succeeded',
        error: null,
        ...overrides,
    });
    const history = { data: [row({}), row({ id: 2, source: { key: 'kobo', name: 'KoboToolbox' }, trigger: 'reconcile' })], current_page: 1, last_page: 1, from: 1, to: 2, total: 2 };
    const sources = [
        { key: 'odk', name: 'ODK Central' },
        { key: 'kobo', name: 'KoboToolbox' },
    ];

    it('names each run\'s source, with the duplicates and cross-source duplicates', async () => {
        const { container } = renderUi(<PullHistoryPanel history={history} filters={{ source: null, trigger: null, outcome: null }} sources={sources} />, translations);

        const [header, odk, kobo] = screen.getAllByRole('row') as [HTMLElement, HTMLElement, HTMLElement];
        expect(within(header).getByRole('columnheader', { name: 'Source' })).not.toBeNull();
        expect(odk.textContent).toContain('ODK Central');
        expect(kobo.textContent).toContain('KoboToolbox');
        expect(kobo.textContent).toContain('Reconcile');
        expect(within(header).getByRole('columnheader', { name: 'From another source' })).not.toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('filters by source through the URL, on the Data sources page', async () => {
        renderUi(<PullHistoryPanel history={history} filters={{ source: null, trigger: null, outcome: null }} sources={sources} />, translations);

        await userEvent.setup().selectOptions(screen.getByLabelText('Source'), 'kobo');

        expect(routerGet).toHaveBeenCalledWith('/admin/sources', { source: 'kobo', page: 1 }, expect.objectContaining({ only: ['history', 'historyFilters'] }));
    });
});

describe('DeletionsPanel', () => {
    const deletion = (overrides: Partial<DeletionRow>): DeletionRow => ({
        submission_id: 9,
        source: { key: 'kobo', name: 'KoboToolbox' },
        instance_id: 'uuid:del-1',
        deleted_at: '2026-10-15T02:00:00Z',
        facility: { id: 31, code: 'KD-0031', name: 'Kujama PHC' },
        round: { id: 2, year: 2026, quarter: 4 },
        assessment: { id: 77, status: 'accepted' },
        quarantined_record_id: null,
        source_url: 'https://kf.example.org/api/v2/assets/aX/data/12/',
        ...overrides,
    });

    it('links each deletion to the record it touches and to its source', async () => {
        const rows = [deletion({}), deletion({ submission_id: 10, instance_id: 'uuid:del-2', facility: null, assessment: null, quarantined_record_id: 4, source_url: null })];
        const { container } = renderUi(<DeletionsPanel deletions={{ rows, total: 3 }} />, translations);

        expect(screen.getByRole('link', { name: 'Open Kujama PHC' }).getAttribute('href')).toBe('/dashboard/facility?year=2026&quarter=4&facility=31');
        expect(screen.getByRole('link', { name: 'Check in KoboToolbox (opens in a new tab)' }).getAttribute('href')).toBe('https://kf.example.org/api/v2/assets/aX/data/12/');
        expect(screen.getByRole('link', { name: 'Open Data issues' }).getAttribute('href')).toBe('/admin/data-issues?round=2');
        expect(screen.getAllByRole('link', { name: /^Check in/ })).toHaveLength(1);
        expect(screen.getByText('Showing the newest 2 of 3.')).not.toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says so when nothing was deleted at source', () => {
        renderUi(<DeletionsPanel deletions={{ rows: [], total: 0 }} />, translations);

        expect(screen.getByText('No submission has been deleted at its source.')).not.toBeNull();
    });
});

describe('DuplicateSides', () => {
    const row = { instance_id: 'uuid:kobo-copy', source: { key: 'kobo', name: 'KoboToolbox' } } as DataIssueRow;

    it('shows both sides of a duplicate, each with its source, and links the accepted one', async () => {
        const { container } = renderUi(
            <DuplicateSides row={{ ...row, duplicate_of: { assessment_id: 1, facility_id: 2, source: { key: 'odk', name: 'ODK Central' }, instance_id: 'uuid:odk-first', url: 'https://central.example.org/#/x' } }} />,
            translations,
        );

        const sides = screen.getByLabelText('Both sides of the duplicate');
        expect(sides.textContent).toContain('Already acceptedfrom ODK Central, uuid:odk-first');
        expect(sides.textContent).toContain('This submissionfrom KoboToolbox, uuid:kobo-copy');
        expect(within(sides).getByRole('link', { name: 'Open in ODK Central (opens in a new tab)' }).getAttribute('href')).toBe('https://central.example.org/#/x');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('shows nothing for a record that repeats no assessment', () => {
        const { container } = renderUi(<DuplicateSides row={{ ...row, duplicate_of: null }} />, translations);

        expect(container.textContent).toBe('');
    });
});
