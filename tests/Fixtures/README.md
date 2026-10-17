# ODK fixtures

Golden fixtures for ingestion (ARCHITECTURE.md §5): what ODK Central sends, what the parser must
read from it, and what validation must then decide. Each case is three files with one name:

| File | What it is |
|---|---|
| `odk/{valid,broken}/<name>.json` | One entry of an ODK Central OData Submissions response (`GET …/forms/{form}.svc/Submissions`): `__id`, `__system` (submissionDate, updatedAt, submitterId, submitterName, attachmentsPresent, attachmentsExpected, status, reviewState, deviceId, edits, formVersion), `meta.instanceID`, groups as nested objects, scored questions inside their dimension group |
| `parsed/{valid,broken}/<name>.json` | `ParsedSubmission::toArray()` of that entry, exactly |
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

1. `SubmissionParser::parse(raw)->toArray()` equals the parsed fixture.
2. The parsed fixture round-trips through `ParsedSubmission`.
3. Run through the parser and `ProcessParsedBatch` against `FixtureWorld`, the case ends with
   exactly its expected outcome.

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

## Changing them

Add or change a case in `generate.mjs` (one line in `CASES`), then run
`node tests/Fixtures/generate.mjs` from the repo root. It writes all three files from the case's
spec, **never through the parser**, so the golden test is a check on the parser, not a copy of it.
If a case needs database state, add it to `FixtureWorld`.

The field maps behind these files are **assumed** from ARCHITECTURE.md §5.1
(`config/edqa_field_maps.php`, every path marked `CONTRACT-ASSUMED`). When the real form XML is
audited (§11.5), replace the maps and regenerate the raw fixtures from real submissions.
