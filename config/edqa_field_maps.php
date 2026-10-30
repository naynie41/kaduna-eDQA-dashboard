<?php

declare(strict_types=1);

/*
 * Field maps (ARCHITECTURE.md §5.1, §5.5), read by App\Domain\Ingestion\FieldMap.
 *
 * 'maps' are named. A map is either full or ADDITIVE: with 'extends' it lists only the paths that
 * differ from the map it extends, plus 'renames' for scored questions it names differently
 * ([name in this form => name in the base form]).
 *
 * 'sources' says, for each source, which map each of its form versions uses: ODK Central labels a
 * version with __system/formVersion, KoboToolbox with its deployment __version__. The same
 * XLSForm deployed on both platforms points at one map. A (source, version) pair with no entry
 * quarantines under UNKNOWN_FORM_VERSION: never fall back to another version's map (CLAUDE.md
 * hard rule 12).
 *
 * Paths are dot paths into a submission's answers after its source's normaliser
 * (SubmissionEnvelope::$data): a group is a nested object, so facility.lga reads
 * {"facility": {"lga": …}}. The source's own fields (ODK's __id, __system, meta; Kobo's _id,
 * _submission_time, …) are in the envelope's meta, not mapped here.
 *
 * Scored questions are not listed: every leaf whose name (after renames) matches
 * scored_prefix_pattern is one, wherever its group. Answers are mapped to pass / fail / na with
 * the scoring rule version's choice_map (Q-25 in the build-era numbering), not here.
 *
 * The real maps come from the form XML of every deployed version on each source (Phase 0 task,
 * ARCHITECTURE.md §5.5), which waits for credentials.
 *
 * Version keys contain dots, so read config('edqa_field_maps.sources')[$source][$version]; never
 * config("edqa_field_maps.sources.{$source}.{$version}"), which splits '2026.1' at the dot.
 */

return [

    'maps' => [
        // The current form, as ARCHITECTURE.md §5.1 specifies it.
        'xls-2026.1' => [
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

        // FICTIONAL older form, to exercise the additive map: the facility group was "fac" with
        // its own question names, the assessor question was "assessor", and the NHMIS summary
        // question was called "nhmis_form" in Availability.
        'xls-2025.2' => [
            'extends' => 'xls-2026.1',
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

    'sources' => [
        // __system/formVersion => map.
        'odk' => [
            '2026.1' => 'xls-2026.1', // CONTRACT-ASSUMED: replace when the real form is audited.
            '2025.2' => 'xls-2025.2', // CONTRACT-ASSUMED: replace when the real form is audited.
        ],
        // __version__ (the Kobo deployment's uid) => map. FICTIONAL uids for the same XLSForms
        // deployed on Kobo, until the real deployed versions are listed (§5.5): any other uid
        // quarantines under UNKNOWN_FORM_VERSION.
        'kobo' => [
            'vKd26Q1aBcDeFgHiJkLmNo' => 'xls-2026.1', // CONTRACT-ASSUMED: replace when the real form is audited.
            'vKd25Q4pQrStUvWxYzAbCd' => 'xls-2025.2', // CONTRACT-ASSUMED: replace when the real form is audited.
        ],
    ],

    // The version each source's current form carries, for code that writes a submission itself
    // (the mock ODK Central, the local demos). Never used to read one.
    'current' => [
        'odk' => '2026.1',
    ],

];
