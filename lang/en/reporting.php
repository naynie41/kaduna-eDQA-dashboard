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

    'errors' => [
        'unsupported_weighting' => 'Aggregation weighting ":weighting" is not defined yet (ARCHITECTURE.md Q-27). Use "unweighted".',
    ],

];
