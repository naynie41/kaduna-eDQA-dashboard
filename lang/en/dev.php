<?php

declare(strict_types=1);

/*
 * /dev/components, the local-only component gallery (Phase 4 step 0). Sample data on that page
 * (LGA names, figures) is illustration, not UI text.
 */

return [
    'title' => 'Components',
    'intro' => 'Every base component in each state, for checking against the prototype. Local environment only; all values are samples.',
    'sections' => [
        'panel' => 'Panel and PanelHeader',
        'score_tag' => 'ScoreTag',
        'status_label' => 'StatusLabel',
        'heat_cell' => 'HeatCell',
        'segmented' => 'SegmentedControl',
        'tabs' => 'Tabs',
        'skeleton' => 'Skeleton',
        'empty' => 'EmptyState',
        'figure' => 'Figure',
        'data_table' => 'DataTable',
        'visually_hidden' => 'VisuallyHidden',
        'announcer' => 'Announcer',
        'tokens' => 'Colour tokens',
    ],
    'sample' => [
        'panel_title' => 'The three dimensions',
        'panel_description' => 'Each facility is scored out of 100 on all three, across the three months of the quarter.',
        'action' => 'Export',
        'metric' => 'Metric',
        'overall' => 'Overall',
        'tabs_label' => 'Dashboard pages',
        'tab_home' => 'Home',
        'tab_facility' => 'Facility view',
        'tab_lga' => 'LGA view',
        'tab_issues' => 'Data issues',
        'tab_lga_badge' => ':count local governments',
        'tab_issues_badge' => ':count open issues',
        'empty_title' => 'No facilities match these filters.',
        'empty_description' => 'Clear a filter to see more.',
        'empty_action' => 'Clear filters',
        'figure_title' => 'Quality score by quarter',
        'figure_description' => 'Overall score across assessed facilities in each round.',
        'figure_chart' => 'Bar chart of the overall score in four rounds',
        'round' => 'Round',
        'table_caption' => 'LGA results',
        'table_empty_caption' => 'LGA results with no rows',
        'lga' => 'LGA',
        'facilities' => 'Facilities',
        'status' => 'Status',
        'month' => 'Month :slot',
        'hidden_demo' => 'The close button below reads "Close" to a screen reader.',
        'close' => 'Close',
        'announce' => 'Announce "Filters applied"',
        'announced' => 'Filters applied: :count facilities',
        'last_announced' => 'Last announced: :message',
        'nothing_announced' => 'Nothing announced yet.',
        'heat_caption' => 'Heat shading from 40 to 100, and no data',
    ],
];
