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

    // SECURITY.md §12: no successful pull for a day while a round is open.
    'stale' => [
        'title' => 'No successful ODK pull for over :hours hours',
        'body' => 'Round Q:quarter :year is open, and the last successful pull was at :time. New submissions are not reaching the dashboard.',
        'body_never' => 'Round Q:quarter :year is open, and no pull has succeeded yet. New submissions are not reaching the dashboard.',
        'link' => 'Go to the ODK pull page',
    ],

];
