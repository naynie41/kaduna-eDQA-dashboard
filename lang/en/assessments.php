<?php

declare(strict_types=1);

// Corrections of an accepted assessment (D-58).

return [
    'corrections' => [
        'errors' => [
            'round_closed' => 'Round :round is closed, so its scores are fixed. Reopen the round on the Rounds page (with a reason) before correcting this assessment.',
            'not_accepted' => 'Only an accepted assessment can be corrected; this one is :status.',
            'no_changes' => 'Nothing would change: every value is already as given.',
            'unknown_item' => ':key is not one of this assessment\'s items.',
            'end_before_start' => 'The visit cannot end before it starts.',
            'outside_round' => 'A visit on :date is outside round :round (:start to :end). A visit belongs to the round its date falls in.',
            'unscorable' => 'This would leave a dimension with nothing to score (every item N/A), so the assessment could not be scored. Leave at least one item in each dimension as pass or fail.',
        ],
        'validation' => [
            'not_a_key' => ':key is not an item name.',
            'not_this_assessment' => ':key is not one of this assessment\'s items.',
        ],
    ],
];
