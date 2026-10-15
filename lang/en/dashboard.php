<?php

declare(strict_types=1);

/*
 * The six dashboard pages (ARCHITECTURE.md §9.2) and the shell parts only they have: the filter
 * bar and the page tabs.
 */

return [
    'pages' => [
        'home' => [
            'title' => 'Home',
            'subtitle' => 'Overall picture of the quarterly data quality assessment across Kaduna State. :count facilities in view.',
        ],
        'facility' => [
            'title' => 'Facility view',
            'subtitle' => 'Results at facility level. Use the filters to narrow to a ward, level or owner. :count facilities in view.',
        ],
        'lga' => [
            'title' => 'LGA view',
            'subtitle' => 'Results aggregated to local government, across all four reports. :count facilities in view.',
        ],
        'monitor' => [
            'title' => 'Monitor',
            'subtitle' => 'The assessment exercise by ownership type and local government. :count facilities in view.',
        ],
        'visuals' => [
            'title' => 'Visuals',
            'subtitle' => 'Scores by month position within the quarter, and ranked by local government. :count facilities in view.',
        ],
        'plan' => [
            'title' => 'Implementation plan',
            'subtitle' => 'Actions generated from the results, with an owner and a review point. :count facilities in view.',
        ],
    ],

    'tabs' => [
        'label' => 'Dashboard pages',
        'lga_badge' => ':count local governments in view',
    ],

    'filters' => [
        'label' => 'Filters',
        'year' => 'Year',
        'quarter' => 'Quarter',
        'lga' => 'LGA',
        'level' => 'Level',
        'owner' => 'Owner',
        'all_years' => 'All years',
        'all_quarters' => 'All quarters',
        'all_lgas' => 'All LGAs',
        'all_levels' => 'All levels',
        'all_owners' => 'Public and private',
        'quarter_option' => 'Q:quarter',
        'quarter_needs_year' => 'Choose a year to pick a quarter.',
        'clear' => 'Clear filters',
        'cleared' => 'Filters cleared.',
        'announce' => 'Showing :count facilities. Average :average.',
        'announce_none' => 'No facilities match these filters.',
    ],

    'home' => [
        'average_label' => 'Average quality score',
        'averaged_across' => 'Averaged across :count facilities and the three months of each assessed quarter.',
        'no_assessments' => 'No assessments match these filters.',
        'facilities' => 'Facilities visited',
        'lgas' => 'Local governments',
        'strong' => 'Facilities scoring :threshold+',
        'weak' => 'Facilities under :threshold',
        'dimensions_title' => 'The three dimensions',
        'dimensions_intro' => 'Each facility is scored out of 100 on all three, across the three months of the quarter. Overall is their mean.',
        'dimension_help' => [
            'availability' => 'Was the source document there to check?',
            'consistency' => 'Does the summary form agree with the register?',
            'validity' => 'Do the entries meet the data element definition?',
            'overall' => 'The mean of the three',
        ],
        'overall' => 'Overall',
        'marker_legend' => 'The mark on each bar is the Strong threshold of :threshold.',
        'weakest_note' => ':dimension is the weakest dimension, at :score.',
        'owners_title' => 'Public and private facilities',
        'public' => 'Public facilities',
        'private' => 'Private facilities',
        'visited' => ':count visited',
        'overall_caption' => 'overall quality score',
        'trend_title' => 'Quality score by quarter',
        'trend_intro' => 'Overall score across assessed facilities in each round. Every round is shown; the round in view is marked.',
        'trend_chart' => 'Line chart of the overall quality score in each round, against the Strong threshold.',
        'strong_line' => 'Strong :threshold',
        'round' => 'Round',
        'in_view' => 'in view',
        'weakest_lgas_title' => 'Weakest local governments',
        'weakest_lgas_intro' => 'Lowest overall quality score in this selection.',
        'weakest_wards_title' => 'Weakest wards in :lga',
        'weakest_wards_intro' => 'Lowest overall quality score among the wards of :lga.',
        'weakest_lga' => 'Local government',
        'weakest_ward' => 'Ward',
        'nothing_to_rank' => 'Nothing to rank in this selection.',
        'empty_title' => 'No assessments match these filters.',
        'empty_description' => 'Choose another round, or clear a filter.',
    ],

    'placeholder' => [
        'title' => 'This page is being built.',
        'description' => 'The shell, filters and data are in place; the page itself arrives in the next Phase 4 steps.',
    ],
];
