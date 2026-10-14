# Changelog

All notable changes to the Kaduna eDQA Portal are recorded here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/releases/tag/v0.1.0
