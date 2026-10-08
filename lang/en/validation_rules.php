<?php

declare(strict_types=1);

/*
 * Validation rules shown on the Data issues page (ARCHITECTURE.md §6), keyed by rule code.
 *
 * name   — short label
 * why    — why the rule exists: the defect in the old Looker Studio report it prevents
 * detail — the specific finding for one submission; the rule fills the :placeholders
 *          (detail_* are the variants a rule picks between)
 */

return [

    // How a round is named in any detail.
    'round_label' => 'Q:quarter :year',

    'UNKNOWN_FORM_VERSION' => [
        'name' => 'Unknown form version',
        'why' => 'Each version of the ODK form names its questions differently. A version the portal has no field map for would be read with the wrong map, and its scores would be silently wrong, so it is held back until a map is added.',
        'detail' => 'form version :version has no field map',
        'blank' => '(blank)',
    ],

    'COLUMN_DRIFT' => [
        'name' => 'Shifted columns',
        'why' => 'In the old report, values landed in the wrong columns: a ward called "Quarterly", a start date of "FEBRUARY". Dates must be real dates, and a ward cannot be a month, a period word or a number.',
        // One entry per shifted field, joined with ", ": "ward 'Quarterly', start 'FEBRUARY'".
        'detail' => ':values',
        'value' => ":field ':value'",
        'fields' => ['ward' => 'ward', 'start' => 'start', 'end' => 'end'],
    ],

    'LGA_UNKNOWN' => [
        'name' => 'Unknown LGA',
        'why' => 'Kaduna State has exactly 23 LGAs. A blank or unrecognised LGA created a 24th one in the old report.',
        'detail' => 'lga was blank',
        'detail_unknown' => "lga ':value' is not one of the 23",
    ],

    'FACILITY_UNKNOWN' => [
        'name' => 'Unknown facility',
        'why' => 'Every assessment must belong to an active facility on the master list. The old report showed a facility named "2019.00".',
        'detail' => "facility ':code' is not on the master list",
        'detail_blank' => 'facility was blank',
        'detail_numeric' => "facility ref ':code' looks like a year or number, not a facility code",
        'detail_inactive' => 'facility :code is deactivated',
    ],

    'FACILITY_LGA_MISMATCH' => [
        'name' => 'Facility in the wrong LGA',
        'why' => 'The facility\'s LGA on the master list must match the LGA submitted. A mismatch usually means the wrong option was picked in the form\'s cascading select.',
        'detail' => "facility ':code' is in :expected on the master list, but the submission says :submitted",
    ],

    'ITEMS_INCOMPLETE' => [
        'name' => 'Missing items',
        'why' => 'Every dimension needs answers for all three months. A missing dimension or month cannot be averaged fairly.',
        'detail' => 'missing :slots',
        'detail_all_na' => ':dimension has no applicable item in any month',
        'detail_typed_scores' => 'no item responses: this form recorded typed scores, which the portal never accepts as scores',
        'separator' => '; ',
    ],

    'SCORE_RANGE' => [
        'name' => 'Score out of range',
        'why' => 'A score is a percentage and must be between 0 and 100. The old report showed scores of 347.66 and 392.42.',
        'detail' => ':slot value :score exceeds 100',
        'detail_below' => ':slot value :score is below 0',
    ],

    'END_BEFORE_START' => [
        'name' => 'Visit ends before it starts',
        'why' => 'A visit cannot end before it begins. The times were entered or recorded wrongly.',
        'detail' => 'visit ended :end, before it started :start',
    ],

    'ROUND_WINDOW' => [
        'name' => 'Outside the round window',
        'why' => 'A visit must fall inside an open round\'s assessment window. Otherwise a visit from one quarter is counted in another.',
        'detail' => "visit date :date is not inside any round's window",
        'detail_closed' => 'visit date :date falls in :round, which is closed',
        'detail_declared' => 'the form says :declared, but visit date :date falls in :round',
        'detail_ambiguous' => "visit date :date falls inside more than one round's window (:rounds)",
    ],

    'DUPLICATE_ASSESSMENT' => [
        'name' => 'Duplicate assessment',
        'why' => 'A facility is assessed once per round. A second assessment would count the facility twice.',
        'detail' => ':round already recorded for facility :code (instance :instance)',
    ],

    // Soft rules: the why text takes its threshold from config (edqa.validation.soft_rules),
    // filled in by whatever shows it, so changing a threshold never leaves stale wording.

    'SCORE_JUMP' => [
        'name' => 'Large score change',
        'why' => 'A facility\'s overall score rarely moves by more than :points points from one round to the next. A jump that large is usually an entry error worth checking.',
        'detail' => 'overall :score, previous round :previous (:change)',
    ],

    'ALL_PERFECT' => [
        'name' => 'Every score perfect',
        'why' => 'All nine dimension-month scores at exactly 100 can mean the form was filled in without a visit.',
        'detail' => 'all :count dimension-month scores are exactly 100',
    ],

    'VISIT_TOO_SHORT' => [
        'name' => 'Very short visit',
        'why' => 'A real assessment takes time. A visit under :minutes minutes may have been rushed or not happened.',
        'detail' => 'visit lasted :minutes minutes (minimum :minimum)',
    ],

    'ASSESSOR_VOLUME' => [
        'name' => 'Many visits in one day',
        'why' => 'One assessor visiting more than :maximum facilities in a day needs a supervisor\'s attention.',
        'detail' => 'assessor :assessor logged :count facilities on :date',
    ],

];
