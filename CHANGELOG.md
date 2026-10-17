# Changelog

All notable changes to the Kaduna eDQA Portal are recorded here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- ODK parsing (Phase 2B step 1, D-48): `App\Domain\Ingestion\Odk\FieldMap` (an additive version
  over the default, dot paths, renames, never a fallback for an unknown version) and
  `SubmissionParser`, which reads one ODK Central OData entry into a `ParsedSubmission` and never
  throws on bad data. Answers map through the published rule version's `choice_map`; an unknown
  answer is `invalid`, and a number in a scored question is a typed score (Q-16).
- `config/edqa_field_maps.php`: a full `2026.1` map following ARCHITECTURE.md §5.1 and a
  fictional additive `2025.2`, every path marked `CONTRACT-ASSUMED`.
- Golden ODK fixtures in `tests/Fixtures`: 23 cases, each a raw OData entry, its parsed form and
  its expected outcome, against `Tests\Support\FixtureWorld`, written by `generate.mjs`. These
  are Phase 2A's cases, which 2A had built in code. A 500-payload property test checks that the
  parser never throws.

- ODK Central client (Phase 2B step 2, D-49): the `SubmissionSource` contract with
  `OdkCentralSource` over `OdkCentralClient`. It has an encrypted session token refreshed 30
  minutes before expiry and once on a 401, https only with TLS verified, 10 s and 60 s timeouts,
  and retries with backoff (Retry-After honoured) on 429, 5xx and connection errors only. Errors
  are typed, carry no secrets, and are logged on the `edqa` channel without passwords, tokens or
  payloads.
- `edqa:odk:check [--json]`: the first command when credentials arrive. It signs in, reads the
  project and form, and lists the published form versions, marking those without a field map.

### Changed

- ITEMS_INCOMPLETE quarantines any answer the form does not allow (`invalid`), even among valid
  answers, which would otherwise score as a fail.
- The field map no longer carries `choices`: answers map through the rule version (Q-25).
- `config/edqa.php` `odk`: `base_url` is now `url`, beside the email, password, project, form,
  page size, timeouts and retry settings (all from the existing `ODK_*` env keys).

## [0.4.0] - 2026-10-17

Phase 4 (Dashboard): step 10 of `CLAUDE.md` §6. The six dashboard pages and the facility detail
drawer, on Inertia + React + TypeScript, each showing only what the Aggregators computed: no
score is rounded, banded, summed or compared in the browser. Filters live in the URL. Server
budgets are met on five years of data; the Slow 3G paint budget is not, pending a decision on
server-side rendering (Q-31). Data is still the synthetic seed; ODK, editing and exports come later.

### Added

- Design tokens (CONVENTION.md §6) and base components: Panel, ScoreTag, HeatCell, StatusLabel,
  SegmentedControl, DataTable (caption, scoped headers, `aria-sort`, a focusable scroll region),
  Figure (every chart with a "Show as table"), Skeleton, EmptyState, ErrorSummary; `lib/heat`,
  `lib/months`, `lib/format`, `lib/i18n`; a component gallery at `/dev/components` (local only).
- App shell (D-37): rail, sync status bar, sticky top bar with the five-filter bar (debounced,
  announced, in the query string), page tabs; `ShareDashboardContext` resolves the filter once a
  request and redirects to the cleaned URL; focus moves to the h1 on page change.
- Home (D-39): hero, the three dimensions with the Strong marker, Public vs Private, the quarter
  trend (every round, the rounds in view marked, a server takeaway), the weakest LGAs, or an
  LGA's weakest wards (`WeakestWardsAggregator`).
- Facility view (D-41): server-sorted, server-paged table (50 a page, weakest first by the chosen
  measure, TanStack Table in manual mode), the measure switcher, partial reloads that keep focus
  and announce the new order.
- Facility detail drawer (D-42): `GET /api/facilities/{id}` (`FacilityDetailAggregator`): score
  card, item responses by dimension × month, round-over-round history, soft flags with overrides,
  attachments, the audit trail and the ODK reference (`AssessmentSourceQuery`, in Ingestion);
  `?facility={id}` in the URL; focus-trapped dialog, full screen under 760px.
- LGA view (D-40): the 23-tile grid toggling the LGA filter, and the table of all four reports.
- Monitor (D-43): owner split, `RankedBars` coverage lists with the state mean in words, coverage
  by level, the assessment window per round.
- Visuals (D-44): the Overall / Public / Private view (`?owner_view=`, locked to the owner filter
  when set), three lazy grouped bar charts with server takeaways and text legends, four LGA
  rankings as band-coloured SVG bars with the score and band printed.
- Implementation plan, read-only (D-45): `ImplementationPlanAggregator`; actions below the
  configured band (`edqa.plan.action_below`, from the rule version), weakest dimension with
  alphabetical ties, default owners by dimension, stored `plan_actions` rows shown instead;
  actions by type and what each action means.
- `round_aggregates` v4 (D-46): each dimension per month slot; the Visuals month series reads it
  for any number of rounds (1,263 ms → 48 ms p95 on the performance dataset).
- `edqa:seed:perf` (local only): five years of scored assessments (~44,000), scores derived from
  items and checked against ScoreCalculator; `ServerTimingTest` in `make test-slow`.
- `make lighthouse` (D-47): Slow 3G Lighthouse runs against a production build.
- `edqa:demo:quarantine` (local only): sends the old report's broken row through the real
  validation pipeline, for the client demo (`docs/demo-script.md`).
- Tests: Vitest with Testing Library and axe for components; Playwright with axe at 360 and
  1280 px for every page and the drawer, forced colours, 200% zoom, reduced motion, keyboard-only
  journeys, and side-by-side screenshots against the prototype (`make e2e`); the bundle budget
  (`make size`).
- Docs: `docs/accessibility-audit-phase4.md` (results, exceptions, the NVDA and TalkBack
  checklist) and `docs/demo-script.md`.

### Changed

- `ARCHITECTURE.md` §9 records where the build refined the spec (the trend ignores year and
  quarter; the weakest wards for a chosen LGA; the drawer's URL and round; the plan threshold
  rule); §10 records the measured budgets.
- `RoundCache::SCHEMA` 5: cached DTOs gained fields (Coverage, MonthSlotPoint, FacilityPage).
- The facility pagination's range and last page come from the server (`FacilityPage`), not
  browser arithmetic. A new client-side architecture test (`resources/js/lib/architecture.test.ts`)
  fails on rounding or summing outside the listed layout helpers, the browser half of hard rule 5.

### Fixed

- Selected states (switches, the current page, the pressed LGA tile, the current tab and section)
  were invisible in forced colours; they now use the system highlight colours.

### Known gaps

- No ODK connection: data is synthetic (`ARCHITECTURE.md` §11.5).
- No editing (plan owners, dates, statuses), no Data issues or other administration pages
  (Phase 5), no exports (Phase 6).
- The Slow 3G paint budget is not met (LCP ≈ 6.5 s); server-side rendering is proposed (Q-31).

## [0.3.0] - 2026-10-14

Phase 3 (Scoring and aggregation): steps 7 and 9 of `CLAUDE.md` §6, plus the round lifecycle.
Every score is derived from item responses under the rule version its round uses; every number
a page will show is computed by an Aggregator, reconciled with the `round_aggregates` view, and
checked against the published reference figures. Data still comes from the synthetic seed:
validating against a real client round waits for ODK access (`ARCHITECTURE.md` §11.5).

### Added

- Rule versions pinned to rounds (D-32): `RuleVersionForRound` decides the version everywhere;
  `rounds.rule_version_id` with CHECK `rounds_closed_has_rule_version`; one draft at a time.
- Scoring rules: `CreateDraftRuleVersion`, `PublishRuleVersion` (reason required, audited) and a
  read-only band-change preview, at `/api/scoring-rules` (list, create, preview, publish).
- Presentation: `ScoreValue` `{ value, display, headline, band, bandLabel }`, `Presentation`
  rounding (`half_up` or `truncate`, Q-09) and `Bands::classify` on the displayed value (Q-26).
- Ten aggregators in `App\Domain\Reporting\Aggregators` (state summary, dimensions, owner split,
  LGA table, facility scores, quarter trend, month slots, coverage, assessment window, weakest
  LGAs), all taking a `ReportFilter`, cached per round by `RoundCache`.
- `round_aggregates` v3: accepted assessments only, each round under its own version; aggregators
  read it when the filter maps onto a view row and fall back to SQL otherwise (D-33).
- `RefreshRoundAggregatesJob`: debounced (unique per round for 60 s), then flushes the round cache.
- Rescore (D-34): `RescoreRound` and a `Bus::batch` of `RescoreRoundJob` on the `rescore` queue,
  200 assessments a chunk; idempotent upserts that touch only changed rows, audited; SCORE_JUMP,
  ALL_PERFECT and all-N/A notes recomputed, overrides kept; progress in `rescore_runs`.
  Triggers: rule publish (open rounds), quarantine resolved, the new `AssessmentCorrected` event,
  `edqa:rescore {round?} {--all-open}` and `POST /api/rounds/{id}/rescore` (reason required),
  with `GET /api/rescores/{batchId}`.
- Round lifecycle (D-35): `CreateRound`, `UpdateRoundWindow`, `CloseRound`, `ReopenRound`, each
  audited, with `GET/POST /api/rounds`, `PATCH /api/rounds/{id}`, `POST /api/rounds/{id}/close`
  and `/reopen`; windows checked against the quarter (45-day grace for late visits) and each
  other; closing pins the version, snapshots the view and dispatches `RoundClosed`.
- `DemoScoringSeeder`: `make fresh` scores the demo data with the real rescore and pins closed
  rounds to version 1; the generator now produces weak LGAs and a few ALL_PERFECT and SCORE_JUMP
  flags. `edqa:report:summary {--round=} {--lga=}` prints the figures without a UI.
- `make test-slow`: the rescore performance test (2,200 assessments; 3.7 s measured, target 5 min).

### Changed

- SCORE_JUMP and ALL_PERFECT share their checks with the rescore (`ScoreFlags`).
- The default suite (`make check`) leaves out the `slow` group.

### Fixed

- `round_aggregates` counted withdrawn assessments in `facility_count` (v3 counts accepted and
  flagged only).
- Every cached dashboard answer came back as `__PHP_Incomplete_Class` on Redis or the database
  cache store: the cache now unserializes only the aggregators' DTOs and enums, and rounds are
  cached as plain columns (D-36).
- `ReferenceFiguresTest` failed intermittently under parallel load: its bulk-loaded tables are now
  analysed, as a live database's are, so the planner no longer picks a minutes-long plan.

### Open

- Q-09 and Q-26 (rounding mode and banding on the displayed value, decided together), Q-27
  (`facility_count` weighting), Q-28 (a facility's score across several rounds), Q-29 (reopening
  a round after a newer version) and Q-20 (audit volume of rescores) await the client.

## [0.2.0] - 2026-10-13

Phase 2A (Validation): the validation gate, step 6 of `CLAUDE.md` §6, plus the pure core of the
score calculator. Every submission is validated before it can influence a number; failures are
quarantined and can be corrected, rejected or superseded. Submissions still arrive from tests:
the ODK client and parser (Phase 2B) wait for credentials.

### Added

- The 14 validation rules, one class each: 10 hard (`UNKNOWN_FORM_VERSION`, `COLUMN_DRIFT`,
  `LGA_UNKNOWN`, `FACILITY_UNKNOWN`, `FACILITY_LGA_MISMATCH`, `ITEMS_INCOMPLETE`, `SCORE_RANGE`,
  `END_BEFORE_START`, `ROUND_WINDOW`, `DUPLICATE_ASSESSMENT`) and 4 soft (`SCORE_JUMP`,
  `ALL_PERFECT`, `VISIT_TOO_SHORT`, `ASSESSOR_VOLUME`), with soft thresholds in config and every
  detail message in `lang/en/validation_rules.php`.
- `ValidationPipeline`: runs every rule and collects every failure, skips field-reading rules
  for an unknown form version, and turns a rule that throws into a hard `RULE_ERROR`.
- `ValidationContext`: everything the rules need, loaded in 7 queries per batch whatever its
  size, and tracking the batch so in-batch duplicates and assessor volume are caught.
- `ProcessParsedSubmission` and `ProcessParsedBatch`: a hard failure becomes a quarantined record;
  otherwise an assessment, item responses and nine scores, with soft flags. ODK edits update the
  assessment in place and supersede the original.
- Pure `ScoreCalculator` with `RuleConfig` and `ScoreCard`: N/A excluded, item weights, full
  precision, and a 1,000-set property test keeping every score within 0–100.
- Quarantine resolution: correct and reprocess (whitelisted fields only, through a correction
  overlay), reject, override a soft flag, bulk resolve, and automatic supersede, each needing a
  reason of 10+ characters and writing one audit entry. `QuarantineResolved` event with a
  placeholder rescore listener.
- API: `GET /api/quarantine` (flat or grouped by rule), `POST /api/quarantine/{id}/resolve`,
  `POST /api/quarantine/bulk-resolve`, `POST /api/assessments/{id}/flags/{code}/override`.
  Domain refusals return 409 `{ code, message }`.
- `quarantined_records.parsed` and `.corrections` columns.
- Broken-submission fixtures: one per rule and live-report defect, each quarantining or flagging
  with exactly its expected codes.
- `edqa` log channel; architecture test allowing only ingestion, validation and the Data issues
  endpoints to touch quarantine or raw submissions.

### Changed

- A visit's round is decided by its date (start, else submission time); a disagreeing round in
  the form quarantines (D-28).
- An assessor is a name plus device ID when present (D-29).

### Fixed

- Every stored timestamp was an hour off: the database session ran in UTC while Laravel writes
  times without an offset. The session now runs in the app time zone and ODK times are converted
  before writing (D-30). Local data written before this needs `make fresh`.

## [0.1.0] - 2026-10-04

Phase 1 (Foundation): build steps 1–5 of `CLAUDE.md` §6. No ODK ingestion, validation, scoring
or dashboard pages yet.

### Added

- Laravel 13 application with Inertia 3, React 19 and strict TypeScript, Tailwind CSS 4 design
  tokens and self-hosted fonts.
- Tooling: Pest 4 against PostgreSQL only, Larastan level 6, Pint, ESLint 9 with strict
  accessibility rules, Vitest, and architecture tests (strict types, no permissions package, no
  CSV import path, Reporting never reads quarantine or raw submissions).
- Containers: one multi-stage `Dockerfile` (targets `app`, `worker`, `web`, `ci`, `dev`), base
  images pinned by digest, `compose.dev.yml` dev stack (app, Caddy web on :8080, Vite, worker,
  opt-in scheduler, Postgres 16, Redis, Mailpit), and a `Makefile` (`up`, `check`, `test`,
  `fresh`, `grants-check`, `prod-build` and more).
- Database schema for every table in `ARCHITECTURE.md` §4, with named CHECK and UNIQUE
  constraints, GIN indexes on `jsonb`, `timestamptz` throughout, and reversible migrations. A
  score outside 0–100, such as the live report's 347.66, is rejected by the database.
- `round_aggregates` materialised view and `refresh_round_aggregates()` (SECURITY DEFINER), with
  least-privilege grants for the `edqa_app` runtime role applied by `edqa:db:apply-grants`.
  `activity_log` is append-only and the LGA list is read-only for that role.
- Domain models, enums, relationships and factories. Raw submission payloads cannot be changed
  after creation.
- Audit trail with `spatie/laravel-activitylog` on every model `SECURITY.md` §4 lists, recording
  old and new values, actor, IP and user agent (or `console`).
- Seeders: the fixed 23 LGAs, wards, scoring rule version 1, and a demo dataset of about 2,200
  synthetic facilities with rounds. Production seeds only the LGAs and rule version 1.
- Authentication with Fortify: email and password sign-in, password reset, password policy
  (12+ characters, mixed case, numbers, not in known breaches), login and 2FA throttling, and
  encrypted 8-hour sessions.
- TOTP two-factor authentication with a setup page. Recovery codes are shown once and stored
  only as SHA-256 hashes, and each works once.
- `edqa:admin:create` and `edqa:admin:disable` commands. There is no registration route.
- One protected route group (`auth`, `verified`, `two-factor.confirmed`), with a route-guard test
  that keeps the unauthenticated allow-list exact.
- Security headers: nonce-based Content Security Policy, HSTS on HTTPS requests, frame, content-type,
  referrer and permissions policies.
- Authentication events (sign-in, sign-out, failed sign-in, 2FA enabled or disabled, recovery
  codes) written to the audit trail.
- App shell (rail and top bar), sign-in and 2FA screens, Security settings page, and a dashboard
  placeholder. Every string comes from `lang/en`.
- Discovery tools in `discovery/`: facility reconciliation, hosting check, and read-only ODK
  Central probes (form versions, field diff, submission profile, rule preview).

### Changed

- The 2FA requirement can be switched off with `EDQA_REQUIRE_2FA=false` (D-26). If the setting is
  unset, 2FA is required.

### Security

- Recovery codes are stored hashed and never shown again, unlike Fortify's default (D-24).
- The session cookie is `Secure` unless `SESSION_SECURE_COOKIE=false`, which is meant for local
  HTTP development only.

[Unreleased]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/releases/tag/v0.1.0
