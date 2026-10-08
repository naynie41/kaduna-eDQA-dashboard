<?php

declare(strict_types=1);

/*
 * ODK question names per form version (ARCHITECTURE.md §5.5). The parser reads a submission
 * through the map for its __system/formVersion. A version with no entry here quarantines under
 * UNKNOWN_FORM_VERSION: never fall back to the default map (CLAUDE.md hard rule 12).
 *
 * PLACEHOLDER: '2026.1' follows the shape in ARCHITECTURE.md §5.5, not the real form. The real
 * maps come from the form XML of every published version (discovery/form_versions.py and
 * field_diff.py), which waits for ODK credentials (ARCHITECTURE.md §11.5). Older versions are
 * additive: only the names that differ from the default, plus 'renames' for scored items.
 *
 * Version keys contain dots, so read config('edqa_field_maps.versions')[$version]; never
 * config("edqa_field_maps.versions.{$version}"), which splits '2026.1' at the dot.
 */

return [

    'default' => '2026.1',

    'versions' => [
        '2026.1' => [
            'facility_code' => 'facility/facility_code',
            'lga_code' => 'facility/lga',
            'ward_code' => 'facility/ward',
            'round_year' => 'round_year',
            'round_quarter' => 'round_quarter',
            'started_at' => 'start',
            'ended_at' => 'end',
            'assessor' => 'assessor_name',
            'scored_prefix_pattern' => '/^(avail|consist|valid)_m([1-3])_([a-z0-9_]+)$/',
            'choices' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
        ],
    ],

];
