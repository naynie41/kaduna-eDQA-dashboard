<?php

declare(strict_types=1);

use App\Support\Config\EnvFlag;

return [

    'auth' => [
        // SECURITY.md §2 / D-26: 2FA is required unless EDQA_REQUIRE_2FA is explicitly false.
        // When off, an account that has set up 2FA is still challenged at sign-in.
        'require_two_factor' => EnvFlag::enabledUnlessFalse(env('EDQA_REQUIRE_2FA')),
    ],

    'validation' => [
        // ARCHITECTURE.md §6, in catalogue order: the pipeline runs them in this order and
        // UNKNOWN_FORM_VERSION must come first. Strings, not ::class: the rule classes arrive
        // in step 2B, one per line here (CONVENTION.md §5: <Code>Rule).
        'rules' => [
            'UNKNOWN_FORM_VERSION' => 'App\Domain\Validation\Rules\UnknownFormVersionRule',
            'COLUMN_DRIFT' => 'App\Domain\Validation\Rules\ColumnDriftRule',
            'LGA_UNKNOWN' => 'App\Domain\Validation\Rules\LgaUnknownRule',
            'FACILITY_UNKNOWN' => 'App\Domain\Validation\Rules\FacilityUnknownRule',
            'FACILITY_LGA_MISMATCH' => 'App\Domain\Validation\Rules\FacilityLgaMismatchRule',
            'ITEMS_INCOMPLETE' => 'App\Domain\Validation\Rules\ItemsIncompleteRule',
            'SCORE_RANGE' => 'App\Domain\Validation\Rules\ScoreRangeRule',
            'END_BEFORE_START' => 'App\Domain\Validation\Rules\EndBeforeStartRule',
            'ROUND_WINDOW' => 'App\Domain\Validation\Rules\RoundWindowRule',
            'DUPLICATE_ASSESSMENT' => 'App\Domain\Validation\Rules\DuplicateAssessmentRule',
            'SCORE_JUMP' => 'App\Domain\Validation\Rules\ScoreJumpRule',
            'ALL_PERFECT' => 'App\Domain\Validation\Rules\AllPerfectRule',
            'VISIT_TOO_SHORT' => 'App\Domain\Validation\Rules\VisitTooShortRule',
            'ASSESSOR_VOLUME' => 'App\Domain\Validation\Rules\AssessorVolumeRule',
        ],

        // Soft rules flag an accepted assessment; they never quarantine.
        'soft_rules' => [
            'score_jump_points' => 30,     // SCORE_JUMP: overall moved more than this since last round
            'visit_min_minutes' => 20,     // VISIT_TOO_SHORT
            'assessor_max_per_day' => 6,   // ASSESSOR_VOLUME: facilities per assessor per day
        ],

        // COLUMN_DRIFT: values that land in the ward column when ODK columns shift
        // (live report: Ward = "Quarterly"). Compared lower-case; numeric wards are caught too.
        'reserved_ward_words' => [
            'quarterly', 'monthly', 'annual', 'null', 'n/a', 'none',
            'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
            'september', 'october', 'november', 'december',
        ],
    ],

];
