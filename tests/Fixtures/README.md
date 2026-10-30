# Ingestion fixtures

Golden fixtures for ingestion (ARCHITECTURE.md §5): what each source sends, what the parser must
read from it, and what validation must then decide. Each case has the same name in every file:

| File | What it is |
|---|---|
| `sources/kobo/{valid,broken}/<name>.json` | The same visit as one row of a KoboToolbox KPI v2 data response (`GET /api/v2/assets/{uid}/data/?format=json`): flat slash keys (`facility/lga`, `availability/avail_m1_…`), every value text, `meta/instanceID`, `formhub/uuid`, `__version__` (the deployment uid) and Kobo's own underscore fields (`_id`, `_uuid`, `_submission_time` with no zone, `_submitted_by`, `_attachments`, `_status`, `_geolocation`, `_tags`, `_notes`, `_validation_status`, `_xform_id_string`) |
| `sources/odk/{valid,broken}/<name>.json` | One entry of an ODK Central OData Submissions response (`GET …/forms/{form}.svc/Submissions`): `__id`, `__system` (submissionDate, updatedAt, submitterId, submitterName, attachmentsPresent, attachmentsExpected, status, reviewState, deviceId, edits, formVersion), `meta.instanceID`, groups as nested objects, scored questions inside their dimension group |
| `parsed/{valid,broken}/<name>.json` | `ParsedSubmission::toArray()` of that entry, exactly (with `source` and `external_id`, its `__id`) |
| `parsed/{valid,broken}/<name>.expected.json` | The outcome: `status` (`accepted`, `flagged` or `quarantined`) and `codes`, in rule order |

**Checked against the ODK Central OData docs (October 2026):**

- Groups are nested objects, `meta.instanceID` is a string, and numbers are JSON numbers.
- A geopoint is GeoJSON in (lon, lat, alt) order. Central's OData **omits GPS accuracy**, so the
  fixtures have none.
- The docs name `__system/submitterId`, `submissionDate`, `updatedAt` and `reviewState`. The
  other `__system` keys here (submitterName, attachmentsPresent, attachmentsExpected, status,
  deviceId, edits, formVersion) come from Central's `$metadata` and are not in the docs' example.
  **Confirm them against a live server** (§11.5) before relying on `deviceId` or `formVersion`.

`tests/Feature/Ingestion/OdkFixturesTest.php` checks three things for every case:

1. The raw entry through ODK's normaliser and then the parser (`OdkPayloadNormalizer`, then
   `SubmissionParser::parse(envelope)`, as the pipeline reads it) equals the parsed fixture.
2. The parsed fixture round-trips through `ParsedSubmission`.
3. Run through the parser and `ProcessParsedBatch` against `FixtureWorld`, the case ends with
   exactly its expected outcome.

**Kobo rows, checked against the KPI API v2 schema (October 2026):** groups come as flat
"group/question" keys, `_id` is a number (the same through edits), `_submission_time` has no
zone (UTC), and a geopoint is the XForms text "lat lng alt accuracy". The parity rows have
"lat lng alt" so they match ODK's accuracy-less point. The `__version__` uids are **fictional**
(`config/edqa_field_maps.php`, CONTRACT-ASSUMED). An unmapped version is written
`vKdUnmapped<version>`.

`tests/Feature/Ingestion/SourceParityTest.php` holds the two sources to the same answer
(CONVENTION.md §8):

1. Every ODK case has a Kobo row.
2. Each row through its own normaliser gives the same `ParsedSubmission`, apart from what each
   source names its own way: `source`, `external_id` (Kobo's `_id`) and `form_version`
   (`2026.1` against a deployment uid).
3. Every Kobo row, through `ProcessParsedBatch` as a `kobo` submission, ends with the same
   `.expected.json` outcome.

The Kobo-only cases have no ODK row and are compared with their parsed fixture exactly.

The outcomes are the Phase 2A ones. 2A built its cases in code (`tests/Support/BrokenSubmissions.php`,
against factory data); these files are the same cases, fixed to one world.

## The world

`tests/Support/FixtureWorld.php` builds the database every case runs against. Every code a
fixture names exists there:

- **LGAs:** the 23, from `LgaSeeder` (CHK Chikun, ZAR Zaria, …).
- **Ward:** CHK-KUJ Kujama, in Chikun.
- **Facilities:** FX-0001 to FX-0006 and FX-0101 to FX-0106, each set up for its case.
- **Rounds:** 2026 Q1 closed, and 2026 Q2 open (1 April to 30 June).
- **Rules:** scoring rule version 1, published.

The ordinary visit is FX-0001 on 14 April 2026, 09:00 to 09:45 (+01:00), by Hauwa Ibrahim. It has
four items per dimension and month (nhmis_summary, register_present, tally_sheet, reporting_rate),
the last answered "no", so each slot scores 75.

## The cases

| Case | What is wrong | Outcome |
|---|---|---|
| `valid/clean-visit` | Nothing | accepted |
| `valid/older-version-2025-2` | Fictional older form `2025.2`: facility group `fac`, question `assessor`, `avail_m*_nhmis_form`; read through the additive map | accepted |
| `broken/unknown-form-version` | `formVersion` 2021.3, which has no map: parsed to minimal fields only | UNKNOWN_FORM_VERSION |
| `broken/shifted-columns` | Ward "Quarterly", start "FEBRUARY" | COLUMN_DRIFT |
| `broken/blank-lga`, `unknown-lga` | No LGA; "Kaduna Central" | LGA_UNKNOWN |
| `broken/numeric-facility`, `deactivated-facility` | Facility "2019.00"; FX-0004 | FACILITY_UNKNOWN |
| `broken/facility-in-another-lga` | FX-0001 (Chikun) sent with ZAR | FACILITY_LGA_MISMATCH |
| `broken/missing-slot` | No validity month 3 questions | ITEMS_INCOMPLETE |
| `broken/typed-scores-only` | Only a typed `avail_m1_score: 92.5` (Q-16) | ITEMS_INCOMPLETE |
| `broken/unexpected-choice` | `avail_m1_tally_sheet: "maybe"`, parsed as `invalid` | ITEMS_INCOMPLETE |
| `broken/typed-score-347-66` | A typed `avail_m1_score: 347.66` beside full items | SCORE_RANGE |
| `broken/ends-before-start` | Ends 08:30 | END_BEFORE_START |
| `broken/visit-in-closed-round`, `visit-outside-every-round`, `form-names-another-round` | 14 January (Q1 closed); 30 July (no round); declares Q3 | ROUND_WINDOW |
| `broken/second-visit-in-round` | FX-0002, already assessed in Q2 | DUPLICATE_ASSESSMENT |
| `broken/live-report-row` | The old report's row: no LGA, ward "Quarterly", facility "2019.00", 347.66 | COLUMN_DRIFT, LGA_UNKNOWN, FACILITY_UNKNOWN, SCORE_RANGE |
| `broken/score-jump` | FX-0003: 75 now, 40 in Q1 | flagged SCORE_JUMP |
| `broken/all-perfect` | Every answer "yes" | flagged ALL_PERFECT |
| `broken/twelve-minute-visit` | 09:00 to 09:12 | flagged VISIT_TOO_SHORT |
| `broken/seventh-visit-of-the-day` | A. Bello's seventh visit on 14 April | flagged ASSESSOR_VOLUME |

**Kobo only** (`sources/kobo/` and `parsed/` only):

| Case | What is odd | Outcome |
|---|---|---|
| `valid/kobo-missing-meta-block` | No `meta/*` keys at all: the instance ID comes from `_uuid` | accepted |
| `broken/kobo-select-multiple-answer` | `avail_m1_tally_sheet: "yes no"`, a select_multiple's space-separated answer in a select_one question, parsed as `invalid` | ITEMS_INCOMPLETE |

## Changing them

Add or change a case in `generate.mjs` (one line in `CASES`), then run
`node tests/Fixtures/generate.mjs` from the repo root. A Kobo-only case goes in `KOBO_ONLY`. It
writes every file (the ODK entry, the Kobo row, parsed and expected) from the case's
spec, **never through the parser**, so the golden test is a check on the parser, not a copy of it.
If a case needs database state, add it to `FixtureWorld`.

The field maps behind these files are **assumed** from ARCHITECTURE.md §5.1
(`config/edqa_field_maps.php`, every path marked `CONTRACT-ASSUMED`). When the real form XML is
audited (§11.5), replace the maps and regenerate the raw fixtures from real submissions.
