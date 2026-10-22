<?php

declare(strict_types=1);

// The administration pages (ARCHITECTURE.md §9.3): the tabs, each page's title, and the banner
// every admin page can show. Shared with admin pages only (HandleInertiaRequests).

return [

    'tabs' => [
        'label' => 'Administration pages',
        // A tab's accessible name with its badge: "Data issues, 12 open".
        'data_issues_open' => ':label, :count open',
    ],

    'pages' => [
        'odk' => [
            'title' => 'ODK pull',
            'subtitle' => 'The connection to ODK Central, Pull now and the pull history.',
        ],
        'data_issues' => [
            'title' => 'Data issues',
            'subtitle' => 'Submissions held back by validation, and the flags on accepted ones.',
        ],
        'rounds' => [
            'title' => 'Rounds',
            'subtitle' => 'Assessment rounds: their windows, closing and reopening.',
        ],
        'facilities' => [
            'title' => 'Facility list',
            'subtitle' => 'The master facility list and the ODK cascade file.',
        ],
        'scoring_rules' => [
            'title' => 'Scoring rules',
            'subtitle' => 'Bands, item weights and the N/A policy, versioned.',
        ],
    ],

    'placeholder' => [
        'title' => 'This page is being built',
        'description' => 'It arrives in a later Phase 5 step. Its data is already served by the portal.',
    ],

    // The ODK pull page (D-56).
    'odk' => [
        'connection' => [
            'title' => 'Connection to ODK Central',
            'not_configured' => 'Not configured',
            'not_configured_body' => 'ODK Central is not configured, so nothing is being pulled. These settings are missing from the server\'s .env (their values are never shown here):',
            'runbook' => 'How to connect ODK Central: the credentials-day runbook',
            'runbook_file' => 'How to connect ODK Central: docs/runbooks/odk-credentials-day.md in the project.',
            'reachable' => 'Connected',
            'reachable_at' => 'ODK Central answered at :time.',
            'unreachable' => 'Not reachable',
            'unreachable_body' => 'ODK Central did not answer when checked at :time: :message',
            'last_success' => 'Last successful pull',
            'never' => 'Never',
            'never_inline' => 'never',
            'ago' => [
                'minutes' => ':count minutes ago',
                'hours' => ':count hours ago',
                'days' => ':count days ago',
            ],
            'last_success_value' => ':ago (:time)',
            'last_pull' => 'Submissions in the last pull',
            'last_pull_value' => ':fetched fetched: :accepted accepted, :flagged flagged, :quarantined quarantined, :duplicates already stored',
            'no_pull' => 'No pull has finished yet.',
            'open_round' => 'Q:quarter :year (open), so far',
            'open_round_value' => ':accepted accepted, :flagged flagged, :quarantined in quarantine',
            'no_open_round' => 'No round is open.',
            'open_rounds' => 'Open rounds',
            'cursor' => 'Where the pull stands',
            'cursor_value' => 'New submissions up to :pulled; edits up to :edited; :count submissions stored.',
            'cursor_none' => 'Not started: the first pull reads from the beginning.',
            'not_yet' => 'none yet',
            'last_error' => 'Last error',
            'error_status' => ':who returned HTTP :status at :time; last success :success.',
            'error_class' => ':what at :time; last success :success.',
            'errors' => [
                'OdkUnreachable' => 'ODK Central could not be reached',
                'OdkAuthFailed' => 'ODK Central refused the service account',
                'OdkUnexpectedResponse' => 'ODK Central sent an answer the portal cannot read',
                'QueryException' => 'The portal\'s database refused a write',
                'other' => 'The pull stopped with an error',
            ],
            'who' => 'ODK Central',
            'error_detail' => 'Detail: :error',
        ],
        'pull' => [
            'title' => 'Pull now',
            'description' => 'Pulls new and edited submissions from ODK Central now, as the ten-minute schedule does. It is recorded in the audit log with your name.',
            'button' => 'Pull now',
            'starting' => 'Starting…',
            'attached' => 'A pull was already running; this follows it.',
            'progress' => 'Pull',
            'stages' => [
                'running' => 'Pulling',
                'succeeded' => 'Finished',
                'failed' => 'Failed',
                'skipped_not_configured' => 'Skipped: ODK Central is not configured',
            ],
            'counters' => 'Progress so far',
            'fetched' => 'Fetched',
            'parsed' => 'Parsed',
            'accepted' => 'Accepted',
            'flagged' => 'Flagged',
            'quarantined' => 'Quarantined',
            'duplicates' => 'Already stored',
        ],
        'history' => [
            'title' => 'Pull history',
            'caption' => 'Every pull, newest first.',
            'started' => 'Started',
            'trigger' => 'Trigger',
            'by' => 'Triggered by',
            'retrieved' => 'Retrieved',
            'accepted' => 'Accepted',
            'flagged' => 'Flagged',
            'quarantined' => 'Quarantined',
            'duration' => 'Duration',
            'duration_value' => ':minutes min :seconds s',
            'duration_seconds' => ':seconds s',
            'outcome' => 'Outcome',
            'system' => 'System',
            'filter_trigger' => 'Trigger',
            'filter_outcome' => 'Outcome',
            'all_triggers' => 'All triggers',
            'all_outcomes' => 'All outcomes',
            'outcomes' => [
                'succeeded' => 'Succeeded',
                'failed' => 'Failed',
                'skipped_not_configured' => 'Skipped (not configured)',
                'running' => 'Running',
            ],
            'none' => 'No pull matches these filters.',
            'showing' => 'Showing :from–:to of :total pulls',
            'showing_none' => 'No pulls yet.',
            'pages' => 'Pull history pages',
            'filtered' => 'Showing :total pulls.',
        ],
        'backfill' => [
            'title' => 'Historical backfill',
            'description' => 'Read-only here: a backfill is started from the server\'s console on purpose (edqa:backfill), out of hours, after the historical rounds exist.',
            'last_run' => 'Last backfill',
            'none' => 'No backfill has run.',
            'run_value' => 'Started :started; :outcome. :fetched read: :accepted accepted, :flagged flagged, :quarantined quarantined, :duplicates already stored.',
            // Inside "Started …; :outcome."
            'outcomes' => [
                'running' => 'still running',
                'succeeded' => 'finished',
                'failed' => 'stopped with an error',
                'skipped_not_configured' => 'skipped, as ODK Central was not configured',
            ],
            'checkpoint' => 'Checkpoint',
            'checkpoint_value' => ':position submissions read, page :page at :size a page (edqa:backfill resumes here).',
            'report' => 'Latest backfill report',
            'report_link' => 'Download the backfill report of :time',
            'report_none' => 'No report yet: run edqa:backfill-report on the server.',
            'report_expiry' => 'The link works for 30 minutes; reload the page for a new one.',
        ],
        'cascade' => [
            'title' => 'ODK facility file',
            'description' => 'The form picks the facility from LGA, ward and facility lists that come from this portal\'s master list (active facilities only). Uploading them to ODK Central is a manual step for now.',
            'button' => 'Regenerate ODK facility file',
            'ready' => 'Ready: :lgas LGAs, :wards wards, :facilities facilities.',
            'download' => 'Download :file',
            'expiry' => 'The link works for 30 minutes.',
            'how' => 'How to upload it to ODK Central',
            'how_file' => 'How to upload it: docs/odk-cascade-media.md in the project.',
        ],
    ],
    // SECURITY.md §12: no successful pull for a day while a round is open.
    'stale' => [
        'title' => 'No successful ODK pull for over :hours hours',
        'body' => 'Round Q:quarter :year is open, and the last successful pull was at :time. New submissions are not reaching the dashboard.',
        'body_never' => 'Round Q:quarter :year is open, and no pull has succeeded yet. New submissions are not reaching the dashboard.',
        'link' => 'Go to the ODK pull page',
    ],

];
