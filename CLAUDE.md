# CLAUDE.md — Kaduna eDQA Portal

Read this at the start of every session. It is the contract for how this codebase is built.
Anything stated here or in the files it points to does not need repeating in prompts.

## Project documents

| File | Read it before |
|---|---|
| `CLAUDE.md` | every session (this file) |
| `ARCHITECTURE.md` | touching domain models, schema, ingestion, validation, scoring, API or page structure |
| `CONVENTION.md` | writing any code, test, migration, component or commit |
| `SECURITY.md` | touching auth, routes, audit, file serving, ODK credentials, webhooks |
| `ACCESSIBILITY.md` | touching any UI. *Not yet in the repo; until supplied, apply WCAG 2.2 AA and CONVENTION.md §6* |
| `DEPLOY.md` | touching the `Dockerfile`, `docker/`, `compose.dev.yml`, or anything the servers depend on (env keys, queues, schedule, volumes, DB roles, health route). Lives in the separate `edqa-infra` DevOps project; a copy is kept here for reference |

Source material: the PRD (*Kaduna eDQA Portal — Product Requirements Document*) and the visual
prototype `kaduna-edqa-prototype-v4.html`. **Where the prototype and the PRD disagree, the PRD
wins** (`ARCHITECTURE.md` §11.2).

---

## 1. What this system is

A Laravel + PostgreSQL application replacing the Kaduna State eDQA Looker Studio report
("Data Quality Assessment e-tool version 3"). It:

1. **Pulls** quarterly Data Quality Assessment submissions from **ODK Central** — the only source.
2. **Validates** every submission before it can influence any number. Failures are quarantined.
3. **Computes** every score server-side from raw item responses. Scores are never accepted from input.
4. **Presents** results on six dashboard pages and five administration pages.

~2,200 facilities, 23 LGAs, quarterly rounds, three dimensions (Availability, Consistency,
Validity) × three month slots. Publication support from HSDF.

> **A score that cannot be true never reaches a chart.**
> Validation is the ingestion gate, not a report feature.

If a change could let an unvalidated row, a score outside 0–100, or a 24th LGA reach any
aggregate, chart or export — stop and flag it.

---

## 2. Stack

Laravel 13 · PHP 8.3 · PostgreSQL 16 (14 min) · Inertia 3 + React 19 + TypeScript (strict) ·
Tailwind CSS 4 · Recharts + hand-built SVG · TanStack Table (server-driven) · Redis queue/cache
(database fallback) · Fortify with 2FA (mandatory unless `EDQA_REQUIRE_2FA=false`, D-26) · maatwebsite/excel · spatie/laravel-pdf
(Browsershot) · spatie/laravel-activitylog · Pest 4 · Larastan level 6 · Pint · Pulse.

**Runtime:** Docker. One multi-stage `Dockerfile` (targets `app`, `worker`, `web`, `ci`, `dev`),
`compose.dev.yml` for local work, Caddy as the web tier, Docker Compose on a single host in
staging/production. The same images run in dev, CI, staging and production.

**Deliberately absent:** `spatie/laravel-permission`, policies, gates, role columns, any
import/upload library, any DHIS2 / Kobo / Google Sheets client.

---

## 3. Hard rules (non-negotiable)

1. **ODK is the only data source.** No import controller, upload route, CSV parser for
   ingestion, or second ingestion path. Export-only CSV is fine. No `Ingestion/Csv/` directory.
2. **Never accept a score from input.** Always derive from item responses.
3. **One role: Administrator.** No permissions package, policy, gate or role column. One
   middleware group (`auth`, `verified`, `two-factor.confirmed`) is the whole authorisation model.
4. **Postgres only.** `jsonb`, `timestamptz`, real CHECK constraints. Every migration reversible.
   Tests run on Postgres, never SQLite.
5. **No aggregate outside an Aggregator class.** Nothing summed, averaged or counted in a
   controller, Resource, Blade view or React component.
6. **No aggregate reads a table that can hold an unvalidated row.** Quarantine lives in
   `quarantined_records`; Reporting never touches it or `submissions` (architecture test).
7. **Every write is audited** — actor, time, old, new, reason where required — and asserted by test.
8. **Raw ODK payloads are never mutated.** Reprocessing reads `submissions.payload`, never ODK.
9. **The LGA list is fixed at 23.** Seeded; no runtime insert path.
10. **Every user-facing string goes through a language file.**
11. **Status is never carried by colour alone**, and every chart has a table equivalent.
12. **Prefer quarantine to guessing.** An unknown form version is quarantined, never parsed with
    the default map.
13. **Containers are stateless.** Persistent files only under `storage/app`; logs to stderr;
    config cached at start, never at build; migrations only in the `migrate` role
    (`CONVENTION.md` §12).

---

## 4. Domain vocabulary (use exactly)

**Round** (year + quarter, open → closed) · **Facility** · **Assessment** (one visit, one facility,
one round) · **Submission** (raw ODK record) · **Dimension** (`availability`, `consistency`,
`validity`) · **Month slot** (1–3; Q2 slot 1 = April) · **Item response** · **Score** (derived,
0–100) · **Quarantine** (hard-rule failure) · **Flag** (soft-rule hit) · **Band** (Strong /
Acceptable / Review / Needs action) · **Rule version**.

Details: `ARCHITECTURE.md` §3.

## 5. Scoring in one block

```
dimension_month_score = items_passed / items_applicable × 100   (N/A excluded from both)
dimension_score       = mean of non-null month scores
overall_score         = mean(availability, consistency, validity)
aggregation           = unweighted mean of facility scores (config flag)
bands (default)       = Strong ≥ 90 · Acceptable 80–89 · Review 70–79 · Needs action < 70
```
Store full precision; round only at presentation. Details and reference figures:
`ARCHITECTURE.md` §7.

---

## 6. Build order

Strictly in sequence. Each step green (Pint, Larastan, Pest) before the next.

| # | Step | Acceptance |
|---|---|---|
| 1 | Scaffold: Laravel, Inertia + React + TS, Pint, Larastan, Pest; **Dockerfile (all targets), `docker/`, `compose.dev.yml`, Makefile**; CI running tests in the `ci` image and building `app`/`worker`/`web` | `make up` serves the app; images build. CI and Trivy deferred (ARCHITECTURE.md §11.5) |
| 2 | Migrations for every table incl. CHECKs, GIN indexes, `refresh_round_aggregates()` — **constraint tests first, watch them fail** | A 347.66 score is rejected by the database |
| 3 | Models, enums, relationships, factories | |
| 4 | Seeders: 23 LGAs, wards, synthetic facilities, four demo rounds | |
| 5 | Fortify + 2FA (mandatory unless `EDQA_REQUIRE_2FA=false`, D-26), `edqa:admin:create`, route-guard test | Only login/2FA/reset, webhook, `/up` unauthenticated |
| 6 | `ValidationRule` contract, all **14** rules, pipeline, quarantine, broken-submission fixtures | Each fixture quarantines with the right code |
| 7 | `ScoreCalculator` + rule versions | Reproduces the published figures — **before anything visual** |
| 8 | ODK client, field maps, pull job, lock, idempotency, edits, backfill, attachments, `odk_pull_runs` | Real submission lands end to end; old form version parses; broken one quarantines |
| 9 | Aggregators + `round_aggregates` view + cache | Aggregates match a known client round |
| 10 | Dashboard: Home, Facility, LGA, Monitor, Visuals, Implementation plan, facility drawer | Performance + accessibility budgets met |
| 11 | Admin: ODK pull, Data issues, Rounds, Facility list, Scoring rules | An admin runs a round without developer help |
| 12 | Exports (CSV, Excel, plan workbook), then PDF round report | |
| 13 | Daily digest, heartbeats, Pulse | |
| 14 | Backfill run + backfill report | Every historical round loaded or gaps explained |

Phase view for the client: Discovery 1 wk · Foundation 1.5 · Ingestion 3 · Scoring 1.5 ·
Dashboard 3.5 · Admin 1.5 · Exports & plan 1.5 · Backfill 1.5 · UAT 1.5 · Deploy & training 1
= 17.5 working weeks (quote 20–22 calendar).

---

## 7. Definition of done

See `CONVENTION.md` §10 and `ACCESSIBILITY.md` §15. In short: tests (happy + 2 failure paths),
Larastan L6, Pint, no N+1, audited writes asserted, strings in lang files, 360px, WCAG AA.

## 8. Commands

Everything runs inside the containers.

```bash
make up                                  # start the dev stack (app, web :8080, vite, worker, postgres, redis, mailpit)
make sh                                  # shell in the app container
make check                               # pint --test, phpstan, pest
make test                                # pest --parallel against edqa_test
make fresh                               # drop, migrate and seed the dev database
make grants-check                        # prove edqa_app's least-privilege grants
make prod-build                          # build the app, worker and web images locally
make routes                              # regenerate Wayfinder route helpers
make artisan c="edqa:pull"               # one ODK pull, synchronous
make artisan c="edqa:backfill"           # resumable historical backfill
make artisan c="edqa:rescore"
make artisan c="edqa:admin:create"
docker compose -f compose.dev.yml --profile scheduler up -d scheduler   # opt-in scheduler
```

## 9. Working agreement for Claude Code

- One build step per session where possible. Start by reading the relevant section of
  `ARCHITECTURE.md`.
- Write the failing test first, then the implementation.
- Do not add a dependency without recording it in `ARCHITECTURE.md` §11.1.
- If the PRD is silent or contradictory on anything that changes a number, **do not guess**:
  add it to `ARCHITECTURE.md` §11.4 (open questions) and ask.
- If a change affects the server contract (env keys, queues, schedule, DB roles, health route,
  migration connection), note it in the PR so `edqa-infra` can be updated (`DEPLOY.md` §1.3).
- End every step with `make check` green and a Conventional Commit.
- Never add `ports:` for Postgres/Redis outside `compose.dev.yml`, never mount the Docker socket,
  and never bake secrets into an image.