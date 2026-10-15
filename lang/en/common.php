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
        'not_connected' => 'ODK Central not connected yet. The dashboard shows the data already loaded.',
        'ok' => 'Last pull :minutes min ago · :assessments assessments · :quarantined in quarantine',
        'stale' => 'Last successful pull :minutes min ago, more than :limit minutes · :assessments assessments · :quarantined in quarantine',
        'failed' => 'Last pull failed at :time (:error) · :assessments assessments · :quarantined in quarantine',
        'failed_no_error' => 'Last pull failed at :time · :assessments assessments · :quarantined in quarantine',
    ],

    // Pages outside the dashboard, for the top bar's title.
    'pages' => [
        'admin' => [
            'title' => 'Administration',
            'subtitle' => 'ODK pull, data issues, rounds, the facility list and scoring rules.',
            'placeholder' => 'The administration pages arrive in Phase 5. Rounds, rescores and scoring rules already work through the API.',
        ],
        'dev' => [
            'title' => 'Components',
        ],
    ],
];
