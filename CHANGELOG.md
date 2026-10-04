# Changelog

All notable changes to the Kaduna eDQA Portal are recorded here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/naynie41/kaduna-eDQA-dashboard/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/naynie41/kaduna-eDQA-dashboard/releases/tag/v0.1.0
