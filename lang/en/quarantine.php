<?php

declare(strict_types=1);

/*
 * Quarantine resolution (ARCHITECTURE.md §6 workflow). errors.* are the domain errors the API
 * returns as 409 { code, message }; the key is the code.
 */

return [

    'errors' => [
        'reason_too_short' => 'Give a reason of at least 10 characters.',
        'already_resolved' => 'This record has already been resolved.',
        'not_a_duplicate' => 'Only a record held as a duplicate of an accepted assessment, and for nothing else, can replace it.',
        'round_closed' => 'Round :round is closed, so its assessments are fixed. Reopen the round on the Rounds page (with a reason) first.',
        'still_failing' => 'This submission would still fail :rules, so it cannot replace the accepted assessment.',
        'field_not_correctable' => 'The field :field cannot be corrected. Only the LGA, ward, facility, start, end and round can.',
        'invalid_correction' => 'The correction for :field is not a valid value.',
        'mixed_rules' => 'Bulk resolution needs every record to fail the same rules.',
        'unknown_action' => 'Unknown resolution: :action.',
        'empty_bulk' => 'Choose at least one record.',
        'record_not_found' => 'No open quarantined record with id :id.',
        'flag_not_found' => 'This assessment is not flagged :code.',
        'not_a_soft_flag' => ':code is not a soft rule flag, so it cannot be overridden.',
    ],

    'superseded_note' => 'Superseded by an edit at source: :instance.',

];
