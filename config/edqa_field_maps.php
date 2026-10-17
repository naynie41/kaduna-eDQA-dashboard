<?php

declare(strict_types=1);

/*
 * ODK question paths per form version (ARCHITECTURE.md §5.1, §5.5), read by
 * App\Domain\Ingestion\Odk\FieldMap. A submission is read through the map for its
 * __system.formVersion; a version with no entry here quarantines under UNKNOWN_FORM_VERSION:
 * never fall back to the default map (CLAUDE.md hard rule 12).
 *
 * The default version is a full map. Any other version is ADDITIVE: it lists only the paths that
 * differ from the default, plus 'renames' for scored questions it names differently
 * ([name in this version => name in the default]). FieldMap::resolve() merges it over the default.
 *
 * Paths are dot paths into one entry of the ODK Central OData Submissions response: a group is a
 * nested object, so facility.lga reads {"facility": {"lga": …}}. __id, __system and meta are
 * ODK's own and the same in every version, so they are not mapped here.
 *
 * Scored questions are not listed: every leaf whose name (after renames) matches
 * scored_prefix_pattern is one, wherever its group. Answers are mapped to pass / fail / na with
 * the scoring rule version's choice_map (Q-25), not here.
 *
 * The real maps come from the form XML of every published version (discovery/form_versions.py
 * and field_diff.py), which waits for ODK credentials (ARCHITECTURE.md §11.5).
 *
 * Version keys contain dots, so read config('edqa_field_maps.versions')[$version]; never
 * config("edqa_field_maps.versions.{$version}"), which splits '2026.1' at the dot.
 */

return [

    'default' => '2026.1',

    'versions' => [
        // The current form, as ARCHITECTURE.md §5.1 specifies it.
        '2026.1' => [
            'facility_code' => 'facility.facility_code', // CONTRACT-ASSUMED: replace when the real form is audited.
            'lga_code' => 'facility.lga', // CONTRACT-ASSUMED: replace when the real form is audited.
            'ward_code' => 'facility.ward', // CONTRACT-ASSUMED: replace when the real form is audited.
            'round_year' => 'round_year', // CONTRACT-ASSUMED: replace when the real form is audited.
            'round_quarter' => 'round_quarter', // CONTRACT-ASSUMED: replace when the real form is audited.
            'started_at' => 'start', // CONTRACT-ASSUMED: replace when the real form is audited.
            'ended_at' => 'end', // CONTRACT-ASSUMED: replace when the real form is audited.
            'assessor' => 'assessor_name', // CONTRACT-ASSUMED: replace when the real form is audited.
            'gps' => 'gps', // CONTRACT-ASSUMED: replace when the real form is audited.
            // Dimension prefix, month slot, item code.
            'scored_prefix_pattern' => '/^(avail|consist|valid)_m([1-3])_([a-z0-9_]+)$/', // CONTRACT-ASSUMED: replace when the real form is audited.
            'renames' => [],
        ],

        // FICTIONAL older version, to exercise the additive map: the facility group was "fac" with
        // its own question names, the assessor question was "assessor", and the NHMIS summary
        // question was called "nhmis_form" in Availability.
        '2025.2' => [
            'facility_code' => 'fac.fac_code', // CONTRACT-ASSUMED: replace when the real form is audited.
            'lga_code' => 'fac.lga_name', // CONTRACT-ASSUMED: replace when the real form is audited.
            'ward_code' => 'fac.ward_name', // CONTRACT-ASSUMED: replace when the real form is audited.
            'assessor' => 'assessor', // CONTRACT-ASSUMED: replace when the real form is audited.
            'renames' => [
                'avail_m1_nhmis_form' => 'avail_m1_nhmis_summary', // CONTRACT-ASSUMED: replace when the real form is audited.
                'avail_m2_nhmis_form' => 'avail_m2_nhmis_summary', // CONTRACT-ASSUMED: replace when the real form is audited.
                'avail_m3_nhmis_form' => 'avail_m3_nhmis_summary', // CONTRACT-ASSUMED: replace when the real form is audited.
            ],
        ],
    ],

];
