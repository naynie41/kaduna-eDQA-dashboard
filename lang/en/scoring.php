<?php

declare(strict_types=1);

/*
 * Scoring rule versions (ARCHITECTURE.md §7). errors.* are the domain errors the API returns as
 * 409 { code, message }; the key is the code.
 */

return [

    // The band label shown when there is no score (ScoreValue::fromNullable).
    'no_data' => 'No data',

    'errors' => [
        // Rule versions
        'draft_exists' => 'There is already a draft. Publish or discard it before starting another.',
        'not_a_draft' => 'Only a draft can be published; this version is already published.',
        'reason_too_short' => 'Give a reason of at least 10 characters.',
        'unknown_config_key' => ':key is not part of a scoring rule. Only bands, item weights, N/A policy and choice map can change.',

        // Config the calculator cannot apply
        'config_missing' => 'The scoring rule has no :part.',
        'band_missing' => 'The :band band has no threshold.',
        'bands_out_of_range' => 'Every band threshold must be between 0 and 100.',
        'bands_not_descending' => 'Bands must go down: Strong above Acceptable above Review.',
        'weight_key_invalid' => 'Item weight ":key" must be written as dimension.item_code.',
        'weight_not_positive' => 'Item weight ":key" must be a number above zero.',
        'choice_outcome_invalid' => 'Choice ":choice" must map to pass, fail or N/A.',
        'choice_map_incomplete' => 'The choice map must lead to all three outcomes: pass, fail and N/A.',
        'na_policy_unknown' => 'N/A policy ":policy" is not supported.',
        'item_value_unmapped' => 'A stored answer ":value" is not in the choice map, so it cannot be scored.',

        // Rescore
        'round_closed' => 'A closed round keeps the scores it was closed with. Reopen it, with a reason, to rescore it.',
    ],

    // edqa:rescore
    'rescore' => [
        'choose' => 'Give a round id, or --all-open, not both.',
        'unknown_round' => 'There is no round :id.',
        'no_open_rounds' => 'There are no open rounds.',
        'started' => 'Round :round (:year Q:quarter): rescoring :count assessments under rule version :version. Batch :batch (run :run).',
    ],

];
