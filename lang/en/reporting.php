<?php

declare(strict_types=1);

return [

    'round_label' => 'Q:quarter :year',

    // A month slot as people read it: "April 2026" (Q2 slot 1 = April).
    'month_label' => ':month :year',

    'months' => [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June',
        7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ],

    // The trend chart's takeaway (QuarterTrendAggregator::takeaway).
    'trend' => [
        'takeaway_single' => ':round: :score.',
        'takeaway_up' => ':round: :score, up :change points from :previous.',
        'takeaway_down' => ':round: :score, down :change points from :previous.',
        'takeaway_unchanged' => ':round: :score, unchanged from :previous.',
    ],

    // edqa:report:summary
    'summary' => [
        'round' => ':round (:status, rule version :version)',
        'state_line' => 'State :overall · :facilities facilities in :lgas LGAs · :strong Strong or better · :weak under Review',
        'weakest' => 'Weakest dimension: :dimension',
        'trend' => 'Quality score by quarter',
        'scope' => 'Scope',
        'lga' => 'LGA',
        'facilities' => 'Facilities',
        'state' => 'State',
        'public' => 'Public',
        'private' => 'Private',
        'overall' => 'Overall',
        'no_rounds' => 'There are no rounds yet.',
        'unknown_round' => 'There is no round ":round". Give a round id, a quarter such as 2026Q2, or "all".',
        'unknown_lga' => 'There is no LGA with code ":lga".',
    ],

    'errors' => [
        'unsupported_weighting' => 'Aggregation weighting ":weighting" is not defined yet (ARCHITECTURE.md Q-27). Use "unweighted".',
    ],

];
