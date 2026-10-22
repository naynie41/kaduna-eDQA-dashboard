<?php

declare(strict_types=1);

return [
    'app_name' => 'Kaduna eDQA',
    'app_tagline' => 'Data Quality Assessment',
    'skip_to_content' => 'Skip to main content',
    'error_summary_title' => 'There is a problem',
    'not_implemented' => 'Not implemented yet.',
    'no_data' => 'No data',
    'loading' => 'Loading',

    // Month names for lib/format.ts and lib/months.ts, January first. Short names are fixed
    // here because Intl writes "Sept" (CONVENTION.md §7: "23 Sep 2026").
    'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'months_short' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    'month_label' => ':month :year',
    'month_slot' => 'Month :slot',

    // Base components (resources/js/components).
    'ui' => [
        'show_table' => 'Show as table',
        'show_chart' => 'Show as chart',
        'scroll_region' => ':label, scrollable',
        'cancel' => 'Cancel',
        'close' => 'Close',
        'working' => 'Working…',
    ],
    // ConfirmWithReasonDialog (CONVENTION.md §6): every reasoned action.
    'reason' => [
        'label' => 'Reason',
        'hint' => 'At least :min characters. It is kept in the audit log with your name.',
        'remaining' => ':count more characters needed.',
    ],
    // ProgressPanel: queued work (a pull, a rescore, an export).
    'progress' => [
        'stage' => ':label: :stage',
        'failed' => 'Could not get the progress. It will be tried again.',
    ],
    // useApiAction: what an answer that is not 2xx says when the server gives no message.
    'api' => [
        'error' => 'Something went wrong. Try again, or reload the page.',
        'forbidden' => 'Your session has ended or this action is not allowed. Reload the page and sign in again.',
        'too_many' => 'Too many requests. Wait a minute and try again.',
        'invalid' => 'Some fields need attention.',
        'conflict' => 'This could not be done as things stand.',
    ],
    'nav' => [
        'label' => 'Main',
        'dashboard' => 'Dashboard',
        'administration' => 'Administration',
        'settings' => 'Settings',
    ],
    'user_menu' => [
        'label' => 'Account',
        'signed_in_as' => 'Signed in as :name',
        'security' => 'Security settings',
        'log_out' => 'Log out',
    ],

    // The shell (ARCHITECTURE.md §9.1).
    'shell' => [
        'seal' => 'KD',
        'brand' => 'eDQA e-tool',
        'brand_place' => 'Kaduna State',
        'assessments' => ':count assessments',
        'support' => 'Support: HSDF',
        'title' => ':page · :app',
    ],
    'sync' => [
        'not_configured' => 'ODK Central is not configured, so no submissions are being pulled. The dashboard shows the data already loaded.',
        'not_connected' => 'ODK Central is configured; no pull has finished yet. The dashboard shows the data already loaded.',
        'ok' => 'Last pull :minutes min ago · :assessments assessments · :quarantined in quarantine',
        'stale' => 'Last successful pull :minutes min ago, more than :limit minutes · :assessments assessments · :quarantined in quarantine',
        'failed' => 'Last pull failed at :time (:error); last success :success · :assessments assessments · :quarantined in quarantine',
        'failed_no_error' => 'Last pull failed at :time; last success :success · :assessments assessments · :quarantined in quarantine',
        'never_succeeded' => 'never',
    ],

    // Pages outside the dashboard, for the top bar's title.
    'pages' => [
        'dev' => [
            'title' => 'Components',
        ],
    ],
];
