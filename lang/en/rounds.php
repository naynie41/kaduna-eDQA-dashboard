<?php

declare(strict_types=1);

/*
 * Round lifecycle (ARCHITECTURE.md §3). errors.* are the domain errors the API returns as
 * 409 { code, message }; the key is the code. warnings.* come back beside a saved round.
 */

return [

    'errors' => [
        'round_exists' => ':round already exists.',
        'window_order' => 'The window must end on or after the day it starts.',
        'window_outside_quarter' => 'The window for :round must start between :start and :end, and end by :latest (:grace days after the quarter, for late visits).',
        'window_overlap' => 'The window overlaps :round. Windows of two rounds may not share a day.',
        'assessments_outside_window' => ':count accepted visit(s) would fall outside the new window. Move them or keep a window that includes them.',
        'round_closed' => 'The round is closed. Reopen it, with a reason, to change it.',
        'already_closed' => 'The round is already closed.',
        'not_closed' => 'Only a closed round can be reopened.',
        'not_fully_scored' => ':count assessment(s) have no scores under the rule version this round would be closed with. Wait for the rescore to finish, then close.',
        'reason_too_short' => 'Give a reason of at least 10 characters.',
    ],

    'warnings' => [
        'window_past_quarter_end' => 'The window ends :days day(s) after the quarter, to take late visits.',
    ],

];
