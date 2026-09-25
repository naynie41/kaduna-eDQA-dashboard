# Kaduna eDQA Portal

Laravel + PostgreSQL replacement for the Kaduna State eDQA Looker Studio report. It pulls
quarterly Data Quality Assessment submissions from ODK Central, validates them, computes
every score server-side, and presents the results on dashboard and administration pages.

**Status:** Phase 0 (Discovery). There is no Laravel app yet; see [`discovery/`](discovery/).

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
discovery/   Phase 0 throwaway Python tools for investigating the ODK Central data (read-only)
```
