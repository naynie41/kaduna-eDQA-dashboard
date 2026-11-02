import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BackfillPanel from '@/components/BackfillPanel';
import CascadePanel from '@/components/CascadePanel';
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
        reason: { label: 'Reason', hint: 'At least :min characters.', remaining: ':count more characters needed.' },
        ui: { ...((testTranslations.common as Record<string, Record<string, string>>).ui ?? {}), cancel: 'Cancel', working: 'Working…' },
        sources: { unknown: 'Unknown source' },
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
                error_status: ':source returned HTTP :status at :time — :meaning',
                meaning: {
                    unauthorized_kobo: 'the API token was rejected.',
                    unauthorized_odk: "the service account's email or password was rejected.",
                    forbidden: 'the account has no access to the form.',
                    not_found: 'the form or project named in the settings was not found.',
                    too_many: 'it asked the portal to slow down.',
                    server: 'its server had a problem.',
                    other: 'the answer was not what the portal expected.',
                },
                consequence: {
                    settings: 'Every scheduled pull from :source will fail the same way until the setting is fixed on the server.',
                    retry: 'The next scheduled pull tries again.',
                },
                last_success_sentence: 'Last success: :success.',
                state: {
                    healthy: 'Healthy',
                    stale: 'Behind',
                    failed: 'Failing',
                    auth_failed: 'Credentials rejected',
                    not_connected: 'Waiting for the first pull',
                },
                state_body: {
                    healthy: 'The last pull succeeded :ago.',
                    stale: 'No successful pull for over :minutes minutes.',
                    failed: 'The last pull failed: see the last error below.',
                    auth_failed: ':source refused the portal\'s credentials: see the last error below.',
                    not_connected: 'No pull has finished yet.',
                },
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
                review: 'Review',
                reviewed: 'Reviewed by :name, :time',
                mark: 'Mark reviewed',
                mark_named: 'Mark :instance reviewed',
                dialog_title: 'Mark this deletion reviewed?',
                dialog_consequence: ':instance is no longer at :source. Marking it reviewed records that you have looked into it. Nothing is removed from the portal, and it stops counting as an open issue.',
                dialog_confirm: 'Mark reviewed',
                saved: 'Deletion marked reviewed.',
            },
            backfill: {
                title: 'Historical backfill',
                description: 'Read-only here.',
                last_run: 'Last backfill',
                none: 'No backfill has run.',
                run_value: 'Started :started; :outcome. :fetched read: :accepted accepted, :flagged flagged, :quarantined quarantined, :duplicates already stored.',
                outcomes: { running: 'still running', succeeded: 'finished', failed: 'stopped with an error', skipped_not_configured: 'skipped' },
                checkpoint: 'Checkpoint',
                checkpoint_value: ':position submissions read, page :page at :size a page (edqa:backfill resumes here).',
                report: 'Latest backfill report',
                report_none: 'No backfill report yet.',
                report_link: 'Download the backfill report of :time',
                report_expiry: 'The link works for 30 minutes.',
            },
            cascade: {
                title: 'Facility media file',
                description: 'The same file is attached to the form on both platforms.',
                button: 'Regenerate facility media file',
                ready: 'Ready: :lgas LGAs, :wards wards, :facilities facilities.',
                download: 'Download :file',
                expiry: 'The link works for 30 minutes.',
                how: 'How to attach it',
                how_file: 'How to attach it: docs/odk-cascade-media.md in the project.',
                steps_label: 'Attach it to the form on both platforms',
                steps: {
                    download: 'Download the file above.',
                    odk: 'ODK Central: open the form, go to Media, upload the file (it replaces the old one), then publish the form.',
                    kobo: "KoboToolbox: open the form's Settings, go to Media, upload the same file, then redeploy the form.",
                },
            },
        },
        issues: {
            table: { source_unknown: 'Unknown source' },
            duplicate: { label: 'Both sides of the duplicate', accepted: 'Already accepted', this: 'This submission', side: 'from :source,', source: 'Source', instance: 'Instance ID', submitted: 'Submitted', assessor: 'Assessor', overall: 'Overall it scores', unknown: 'Not known' },
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
        ['healthy', 'Healthy', 'The last pull succeeded 10 minutes ago.'],
        ['stale', 'Behind', 'No successful pull for over 30 minutes.'],
        ['failed', 'Failing', 'The last pull failed: see the last error below.'],
        ['auth_failed', 'Credentials rejected', "KoboToolbox refused the portal's credentials: see the last error below."],
        ['not_connected', 'Waiting for the first pull', 'No pull has finished yet.'],
    ] as const)('says the source\'s state in words: %s', (state, label, body) => {
        renderUi(panel(source({ status: { ...source().status, state } })), translations);

        const region = screen.getByRole('region', { name: 'KoboToolbox' });
        expect(region.textContent).toContain(label);
        expect(region.textContent).toContain(body);
    });

    it('says what a refused token means, and that every pull will fail until it is fixed', () => {
        renderUi(panel(source({ status: { key: 'kobo', name: 'KoboToolbox', state: 'auth_failed', ...sync, lastFailedAt: '2026-10-15T13:02:00Z', lastErrorClass: 'KoboAuthFailed', lastErrorHttpStatus: 401 } })), translations);

        expect(screen.getByText('Last error').parentElement?.textContent).toContain(
            'KoboToolbox returned HTTP 401 at 14:02 WAT — the API token was rejected. Every scheduled pull from KoboToolbox will fail the same way until the setting is fixed on the server. Last success: never.',
        );
    });

    it('says a server error is temporary, and the next pull tries again', () => {
        renderUi(panel(source({ status: { key: 'kobo', name: 'KoboToolbox', state: 'failed', ...sync, lastFailedAt: '2026-10-15T13:02:00Z', lastErrorClass: 'KoboUnexpectedResponse', lastErrorHttpStatus: 503 } })), translations);

        expect(screen.getByText('Last error').parentElement?.textContent).toContain('KoboToolbox returned HTTP 503 at 14:02 WAT — its server had a problem. The next scheduled pull tries again.');
    });

    it.each([
        [{ lastErrorHttpStatus: 503, lastErrorClass: 'KoboUnexpectedResponse' }, 'KoboToolbox returned HTTP 503 at'],
        [{ lastErrorHttpStatus: null, lastErrorClass: 'KoboAuthFailed' }, "KoboToolbox refused the portal's credentials at"],
        [{ lastErrorHttpStatus: null, lastErrorClass: 'OdkUnreachable' }, 'KoboToolbox could not be reached at'],
        [{ lastErrorHttpStatus: null, lastErrorClass: 'SomethingElse' }, 'The pull stopped at'],
    ] as const)('says the last error in plain language: %o', (error, text) => {
        renderUi(panel(source({ status: { key: 'kobo', name: 'KoboToolbox', state: 'failed', ...sync, lastFailedAt: '2026-10-15T08:05:00Z', ...error } })), translations);

        expect(screen.getByText('Last error').parentElement?.textContent).toContain(text);
        expect(screen.getByText('Last error').parentElement?.textContent).toMatch(/last success:? never/i);
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
        reviewed: null,
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

    it('marks a deletion reviewed with a reason, and says who reviewed one already', async () => {
        const fetch = stubFetch(() => ({ body: { data: { submission_id: 9, reviewed_at: '2026-10-20T09:00:00Z' } } }));
        const onReviewed = vi.fn();
        const rows = [deletion({}), deletion({ submission_id: 10, instance_id: 'uuid:del-2', reviewed: { at: '2026-10-20T09:00:00Z', by: 'Amina Bello' } })];
        const { container } = renderUi(<DeletionsPanel deletions={{ rows, total: 2 }} onReviewed={onReviewed} />, translations);
        const user = userEvent.setup();

        expect(screen.getByText('Reviewed by Amina Bello, 20 Oct 2026, 10:00 WAT')).not.toBeNull();
        expect(screen.queryByRole('button', { name: 'Mark uuid:del-2 reviewed' })).toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();

        await user.click(screen.getByRole('button', { name: 'Mark uuid:del-1 reviewed' }));
        const dialog = screen.getByRole('dialog', { name: 'Mark this deletion reviewed?' });
        expect(dialog.textContent).toContain('uuid:del-1 is no longer at KoboToolbox.');
        await user.type(within(dialog).getByLabelText('Reason'), 'Confirmed with the LGA M&E officer');
        await user.click(within(dialog).getByRole('button', { name: 'Mark reviewed' }));

        await waitFor(() => expect(onReviewed).toHaveBeenCalled());
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/api/submissions/9/deletion-review');
        expect(JSON.parse(String(init.body))).toEqual({ reason: 'Confirmed with the LGA M&E officer' });
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('says so when nothing was deleted at source', () => {
        renderUi(<DeletionsPanel deletions={{ rows: [], total: 0 }} />, translations);

        expect(screen.getByText('No submission has been deleted at its source.')).not.toBeNull();
    });
});

describe('BackfillPanel', () => {
    it('shows each source\'s last backfill and checkpoint, and the newest report', async () => {
        const run = { id: 4, outcome: 'succeeded', started_at: '2026-10-14T21:00:00Z', finished_at: '2026-10-14T23:00:00Z', fetched: 1500, accepted: 1400, flagged: 50, quarantined: 50, duplicates: 0 };
        const { container } = renderUi(
            <BackfillPanel
                backfill={{
                    sources: [
                        { key: 'odk', name: 'ODK Central', run, checkpoint: { position: 1500, page: 3, page_size: 500 } },
                        { key: 'kobo', name: 'KoboToolbox', run: null, checkpoint: { position: 0, page: 0, page_size: 1000 } },
                    ],
                    report: { file: 'backfill-report-20261015-090000.md', at: '2026-10-15T08:00:00Z', url: '/api/backfill-reports/backfill-report-20261015-090000.md?signature=x' },
                }}
            />,
            translations,
        );

        const odk = screen.getByRole('group', { name: 'ODK Central' });
        expect(odk.textContent).toContain('1,500 read');
        expect(odk.textContent).toContain('page 3 at 500 a page');
        expect(screen.getByRole('group', { name: 'KoboToolbox' }).textContent).toContain('No backfill has run.');
        expect(screen.getByRole('link', { name: /Download the backfill report/ }).getAttribute('href')).toContain('signature=');
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('CascadePanel', () => {
    it('regenerates the file, then says how to attach the same file on both platforms and redeploy', async () => {
        stubFetch(() => ({ body: { data: { filename: 'kaduna-facilities.zip', lgas: 23, wards: 255, facilities: 2176, url: '/api/facility-list/cascade/kaduna-facilities.zip?signature=x', expires_at: '2026-10-15T09:00:00Z' } } }));
        const { container } = renderUi(<CascadePanel howUrl={null} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Regenerate facility media file' }));

        expect(await screen.findByRole('link', { name: 'Download kaduna-facilities.zip' })).not.toBeNull();
        const steps = screen.getByRole('list', { name: 'Attach it to the form on both platforms' });
        expect(within(steps).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
            'Download the file above.',
            'ODK Central: open the form, go to Media, upload the file (it replaces the old one), then publish the form.',
            "KoboToolbox: open the form's Settings, go to Media, upload the same file, then redeploy the form.",
        ]);
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('DuplicateSides', () => {
    const row = { instance_id: 'uuid:kobo-copy', source: { key: 'kobo', name: 'KoboToolbox' } } as DataIssueRow;

    const score = (display: string) => ({ value: Number(display), display, headline: display, band: 'acceptable' as const, bandLabel: 'Acceptable' });

    it('compares the two submissions side by side: source, instance, submitted, assessor and the overall each scores', async () => {
        const { container } = renderUi(
            <DuplicateSides
                row={{
                    ...row,
                    duplicate_of: {
                        assessment_id: 1,
                        facility_id: 2,
                        source: { key: 'odk', name: 'ODK Central' },
                        instance_id: 'uuid:odk-first',
                        url: 'https://central.example.org/#/x',
                        submitted_at: '2026-10-14T09:00:00Z',
                        assessor: 'Hauwa Ibrahim',
                        overall: score('81.2'),
                        this: { submitted_at: '2026-10-15T10:30:00Z', assessor: 'Musa Danjuma', overall: score('84.7') },
                    },
                }}
            />,
            translations,
        );

        const table = screen.getByRole('table', { name: 'Both sides of the duplicate' });
        expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Already accepted', 'This submission']);
        const line = (header: string) => within(table).getByRole('rowheader', { name: header }).parentElement?.textContent;
        expect(line('Source')).toContain('ODK Central');
        expect(line('Source')).toContain('KoboToolbox');
        expect(line('Instance ID')).toBe('Instance IDuuid:odk-firstuuid:kobo-copy');
        expect(line('Submitted')).toBe('Submitted14 Oct 2026, 10:00 WAT15 Oct 2026, 11:30 WAT');
        expect(line('Assessor')).toBe('AssessorHauwa IbrahimMusa Danjuma');
        expect(line('Overall it scores')).toContain('81.2');
        expect(line('Overall it scores')).toContain('84.7');
        expect(within(table).getByRole('link', { name: 'Open in ODK Central (opens in a new tab)' }).getAttribute('href')).toBe('https://central.example.org/#/x');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('shows nothing for a record that repeats no assessment', () => {
        const { container } = renderUi(<DuplicateSides row={{ ...row, duplicate_of: null }} />, translations);

        expect(container.textContent).toBe('');
    });
});
