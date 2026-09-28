# Kaduna eDQA Portal

Laravel + PostgreSQL replacement for the Kaduna State eDQA Looker Studio report. It pulls
quarterly Data Quality Assessment submissions from ODK Central, validates them, computes
every score server-side, and presents the results on dashboard and administration pages.

**Status:** Phase 1, build step 1 (scaffold + containers). Laravel 13 + Inertia 3 + React 19 +
TypeScript; no domain code yet.

## Local development

Needs Docker and GNU make. `make up` starts the stack (first run creates `.env`, installs
dependencies, migrates): app on http://localhost:8080, Mailpit on http://localhost:8025,
Vite on :5173. `make help` lists every target; `make check` must be green before a commit.

## Project documents

Read [`CLAUDE.md`](CLAUDE.md) first. It says which of the others to read before which change.

| File | Covers |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Working contract, hard rules, domain vocabulary, build order |
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Domain model, schema, ODK ingestion, validation, scoring, pages, decisions, open questions |
| [`CONVENTION.md`](CONVENTION.md) | Code, test, migration, component and commit conventions |
| [`SECURITY.md`](SECURITY.md) | Auth, routes, audit, file serving, ODK credentials, webhooks |
| `ACCESSIBILITY.md` | UI accessibility requirements. *Not yet in the repository* |
| [`DEPLOY.md`](DEPLOY.md) | Docker images, servers, env keys, queues, schedule (reference copy of `edqa-infra`) |

## Layout

```
app/ config/ database/ routes/   Laravel application
resources/js/                    Inertia + React + TypeScript front end
tests/                           Pest (Arch, Unit, Feature) against PostgreSQL
discovery/                       Phase 0 Python tools for the ODK Central data (read-only)
```

Checks (CONVENTION.md §11): `composer lint`, `composer test`, `composer check`;
`npm run typecheck`, `npm run lint`, `npm run test`.
