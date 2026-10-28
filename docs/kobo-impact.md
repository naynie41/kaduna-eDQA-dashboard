# KoboToolbox impact analysis (Phase 2C, step 0)

What in the current code assumes ODK Central is the only source, which Phase 2C step changes
it, and where the code and the new documents disagree in ways those steps do not cover.

Measured on 28 Oct 2026 against the working tree. That tree holds Phases 1–2B, plus Phase 5
steps 0–3 (admin shell, ODK pull page, Data issues page, assessment corrections). Only the
commit for Phase 5 step 1 (`ebd43e5`) is committed; steps 2 and 3 are still uncommitted.

Documents compared: the new `CLAUDE.md`, `ARCHITECTURE.md` (§4 tables, §5 in full, §8, §9.3,
§11.1 D-21–D-26, §11.4), `CONVENTION.md` and `SECURITY.md` §6. `DEPLOY.md` has not changed (it
has no Kobo content), and `ACCESSIBILITY.md` is still not in the repository.

**Read §3 before starting 2C.1.** It lists problems with the new documents themselves that no 2C
step fixes, including decision and question numbers that collide with ones the code already cites.

---

## 1. The 2C steps used below

The prompt refers to "2C.1–2C.7 below", but the list was not included, and the documents do not
define it. This document uses the breakdown below, which follows ARCHITECTURE.md §5 and the
build-order row 8. **If your list differs, renumber the Step column; the inventory itself does
not change.**

| Step | Scope |
|---|---|
| **2C.1** | Sources in the schema and config: `submission_sources`; `source_id` on `submissions` and `quarantined_records`; `odk_form_syncs` → `source_syncs`, `odk_pull_runs` → `pull_runs`, `odk_attachments` → `submission_attachments` (with data migration); `config/edqa.php` → `sources`; `SourceRegistry`; models and factories |
| **2C.2** | Contracts and normalisers: `PayloadNormalizer`, `SubmissionEnvelope`, `OdkPayloadNormalizer`; `SubmissionParser` reads an envelope; named field maps referenced by (source, version); reprocessing through the submission's own normaliser |
| **2C.3** | The pipeline per source: pull, backfill, locks, cursor and schedule per source; cross-source duplicates; edits detected by `external_id`; commands take `{source}` |
| **2C.4** | The KoboToolbox adapter: `KoboClient`, `KoboSource`, `KoboPayloadNormalizer`, a Kobo mock and fixtures, `edqa:source:check` |
| **2C.5** | Reconcile and deletions at source: `ReconcileSourceJob`, `edqa:reconcile`, `deleted_at_source`, the review list, the nightly sweep |
| **2C.6** | Webhooks, attachments and deep links per source: `POST /webhooks/{source}` (Kobo token header), the attachment SSRF host guard, attachment paths per source, deep links built per source |
| **2C.7** | API and admin UI: `/api/sources…`, `/api/pulls/{id}`, the Data sources page in place of the ODK pull page, shell status per source, the Data issues source filter, the drawer's source section, strings, runbooks, the backfill report by source, e2e |

---

## 2. Everything that assumes ODK is the only source

### 2.1 Tables and columns

| Item | Path | What assumes ODK only | Step |
|---|---|---|---|
| `submissions.form_id` | `database/migrations/2026_09_28_001100_create_submissions_table.php` | No `source_id`, `external_id`, `source_updated_at` or `deleted_at_source`; `form_id` is ODK's form, the new spec calls it `form_ref` | 2C.1 (`deleted_at_source` 2C.5) |
| `submissions.instance_id` UNIQUE | same | Already unique across everything, which D-22 needs; the duplicate *outcome* is ODK-only (no `cross_source_duplicate`) | 2C.3 |
| `quarantined_records` | `…_001600_create_quarantined_records_table.php`, `2026_10_08_000100_add_parsed_and_corrections_to_quarantined_records.php` | No `source_id` | 2C.1 |
| `odk_form_syncs` (`project_id`, `form_id`, `last_pulled_at`, `last_instance_id`, `backfill_skip`, `last_error`, `submission_count`; `last_edit_at` added later; UQ `project_id, form_id`) | `2026_09_28_001800_create_odk_tables.php`, `2026_10_19_000100_extend_odk_pull_tracking.php` | One ODK form's cursor. New spec: `source_syncs` keyed by `source_id`, with `cursor_at`, `cursor_instance_id`, `backfill_checkpoint`, `last_success_at`, `last_reconciled_at`. The second cursor (`last_edit_at`, D-51) has no column in the new spec | 2C.1 |
| `odk_pull_runs` (+ `flagged`, `parsed`; CHECKs `odk_pull_runs_trigger_type_valid`, `_outcome_valid`, `_parsed_nonnegative`) | `…_001800_create_odk_tables.php`, `…_000100_extend_odk_pull_tracking.php`, `2026_10_20_000100_add_backfill_pull_trigger.php`, `2026_10_22_000100_add_parsed_to_odk_pull_runs.php` | New spec: `pull_runs` with `source_id`, `cross_source_duplicates`, and `reconcile` as a trigger | 2C.1 (trigger 2C.5) |
| `odk_attachments` (UQ `submission_id, filename`; CHECKs `_status_valid`, `_size_nonnegative`) | `…_001800_create_odk_tables.php`, `2026_10_19_000200_extend_odk_attachments.php` | New spec: `submission_attachments` with `source_url` | 2C.1, 2C.6 |
| CHECK and constraint tests on all of the above | `tests/Feature/Database/IngestionTablesTest.php` | Name the `odk_*` tables and constraints | 2C.1 |
| Grants | `database/sql/post-migrate-grants.sql`, `app/Console/Commands/ApplyDatabaseGrants.php`, `tests/Grants` | Grant on `odk_*` tables by name | 2C.1 |

### 2.2 Classes (`app/`)

| Item | Path | What assumes ODK only | Step |
|---|---|---|---|
| `Contracts\SubmissionSource` | `app/Domain/Ingestion/Contracts/SubmissionSource.php` | Exists, but `submissionsPage(…, string $by = 'submissionDate')` uses an ODK field name; no `changedSince()`; one binding container-wide | 2C.2, 2C.3 |
| `Odk\OdkCentralSource`, `Odk\OdkCentralClient`, `Odk\SessionTokenCache` | `app/Domain/Ingestion/Odk/` | Become the ODK adapter only; bound as *the* `SubmissionSource` in `AppServiceProvider` | 2C.3 |
| `Odk\SubmissionParser` | `app/Domain/Ingestion/Odk/SubmissionParser.php` | Reads raw ODK JSON (`__id`, `__system.*`, `meta.instanceID`); the new spec has it read a `SubmissionEnvelope`. Moves out of `Odk\` | 2C.2 |
| `Odk\FieldMap` | `app/Domain/Ingestion/Odk/FieldMap.php`, `config/edqa_field_maps.php` | Maps keyed by ODK `formVersion` (`default`, `versions`), dot paths (`facility.facility_code`). New spec: named maps (`xls-2026.1`, `extends`) referenced by (source, version), slash paths | 2C.2 (see also §3.5) |
| `Odk\OdkRow` | `app/Domain/Ingestion/Odk/OdkRow.php` | ODK row shape | 2C.2 |
| `Odk\OdkConfig` | `app/Domain/Ingestion/Odk/OdkConfig.php` | "Is the source configured" for ODK only; the schedule and status read it | 2C.1 |
| `Odk\PullLock`, `Odk\BackfillLock` | `app/Domain/Ingestion/Odk/` | One lock for the whole system; new spec: `pull:{source}` | 2C.3 |
| `Odk\CentralLink` | `app/Domain/Ingestion/Odk/CentralLink.php` | The only deep-link builder (ODK URL shape) | 2C.6 |
| `Actions\PullSubmissions`, `StartManualPull`, `DryRunPull`, `ProcessSubmissionsPage`, `BackfillSubmissions`, `SurveyBackfill`, `FetchAttachments`, `RecordAttachments` | `app/Domain/Ingestion/Actions/` | No source parameter; one cursor row; two-pass ODK cursors (submissionDate, updatedAt, D-51) | 2C.3 (attachments 2C.6) |
| `ProcessParsedBatch`, `ProcessParsedSubmission` | same | Edits detected by `deprecated_id` only; no cross-source duplicate outcome | 2C.3 |
| `Jobs\PullSubmissionsJob(trigger)`, `Jobs\BackfillOdkSubmissionsJob`, `Jobs\FetchAttachmentsJob` | `app/Domain/Ingestion/Jobs/` | No source argument; ODK in the backfill job's name | 2C.3 |
| `Models\OdkFormSync`, `OdkPullRun`, `OdkAttachment` | `app/Domain/Ingestion/Models/` | Models of the `odk_*` tables | 2C.1 |
| `Exceptions\OdkException`, `OdkAuthFailed`, `OdkNotConfigured`, `OdkUnexpectedResponse`, `OdkUnreachable` | `app/Domain/Ingestion/Exceptions/` | Shared code catches ODK-named exceptions; needs source-neutral parents (Kobo: auth failure stops pulls, no retry loop) | 2C.3, 2C.4 |
| `Enums\PullTrigger` (`scheduled`, `manual`, `webhook`, `backfill`) | `app/Domain/Ingestion/Enums/PullTrigger.php` | No `reconcile` | 2C.5 |
| `Enums\PullOutcome` / duplicate counting | `app/Domain/Ingestion/Enums/PullOutcome.php`, `DTOs/` | No `cross_source_duplicates` counter | 2C.3 |
| `Queries\OdkStatusQuery`, `PullHistoryQuery`, `BackfillReportQuery`, `AssessmentSourceQuery`, `AttachmentFileQuery` | `app/Domain/Ingestion/Queries/` | One source's status and history; no source column or filter | 2C.7 (report 2C.3) |
| `Reports\BackfillReportWriter` | `app/Domain/Ingestion/Reports/BackfillReportWriter.php` | No breakdown by source | 2C.3 |
| `Actions\SendDemoRowToQuarantine`, `SendDemoDataIssues` | `app/Domain/Ingestion/Actions/` | Build ODK-shaped payloads (`__system.formVersion`) | 2C.2 |
| `Support\OdkMock\MockSubmissionGenerator` | `app/Support/OdkMock/` | ODK mock only; a Kobo mock is needed | 2C.4 |
| `Validation\Rules\DuplicateAssessmentRule`, `UnknownFormVersionRule`, `ColumnDriftRule` | `app/Domain/Validation/Rules/` | Details do not name the source (new §5.5, §5.8 require it) | 2C.3 |
| `Validation\Actions\CorrectAndReprocess`, `MarkSuperseded` | `app/Domain/Validation/Actions/` | Reprocess through the ODK parser directly; superseded = ODK edit | 2C.2, 2C.3 |
| `Validation\ValidationContextFactory` | `app/Domain/Validation/ValidationContextFactory.php` | Known form versions are ODK versions only | 2C.2 |
| `Reporting\Aggregators\ShellStatusAggregator` | `app/Domain/Reporting/Aggregators/ShellStatusAggregator.php` | One sync status (ODK) for the status bar and the stale-ingestion banner | 2C.7 |
| `Reporting\Aggregators\FacilityDetailAggregator`, `DTOs\FacilityDetail` | `app/Domain/Reporting/` | Drawer `odk` block (instance ID, ODK URL) | 2C.6, 2C.7 |
| `Facility\Exports\CascadeExporter` | `app/Domain/Facility/Exports/CascadeExporter.php` | "ODK facility file"; the same file now goes to both sources | 2C.7 |
| `Http\Controllers\Odk\OdkPullController`, `OdkStatusController`, `OdkStatusPayload`, `BackfillReportDownloadController` | `app/Http/Controllers/Odk/` | One source; ODK routes | 2C.7 |
| `Http\Controllers\OdkWebhookController` | `app/Http/Controllers/OdkWebhookController.php` | ODK only | 2C.6 |
| `Http\Controllers\Admin\AdminOdkController` | `app/Http/Controllers/Admin/AdminOdkController.php` | The ODK pull page | 2C.7 |
| `Http\Resources\Odk\OdkPullRunResource`, `Requests\Admin\PullHistoryRequest` | `app/Http/` | No source field or filter | 2C.7 |
| `Http\Resources\Quarantine\DataIssueRowResource`, `DataIssueDetailResource`; `Http\Controllers\Quarantine\DataIssuesPageController`; `Requests\Admin\DataIssuesRequest` | `app/Http/` | `odk_url` via `CentralLink`; no `source` filter | 2C.6, 2C.7 |
| `Http\Controllers\Attachments\ShowAttachmentController` | `app/Http/Controllers/Attachments/` | Serves from the `odk` disk | 2C.6 |
| `Http\Middleware\HandleInertiaRequests` | `app/Http/Middleware/HandleInertiaRequests.php` | Shares one source's sync status | 2C.7 |
| `Providers\AppServiceProvider` | `app/Providers/AppServiceProvider.php` | Binds the ODK source; `odk-pull` rate limiter | 2C.3 |
| `Support\Text\Normalise` | `app/Support/Text/Normalise.php` | Comment only (ODK text quirks); no change expected | — |

### 2.3 Routes and the schedule

| Item | Path | New spec | Step |
|---|---|---|---|
| `POST /webhooks/odk` (`webhooks.odk`) | `routes/web.php` | `POST /webhooks/{source}`; Kobo token header | 2C.6 |
| `GET /admin` → `/admin/odk`; `GET /admin/odk` (`admin.odk`) | `routes/web.php` | Data sources page | 2C.7 |
| `POST /api/odk/pull`, `GET /api/odk/pull/{run}`, `GET /api/odk/status` | `routes/web.php` | `POST /api/sources/{key}/pull`, `POST /api/sources/pull-all`, `GET /api/pulls/{runId}`, `GET /api/sources`, `POST /api/sources/{key}/reconcile` | 2C.7 (reconcile 2C.5) |
| `GET /api/odk/backfill-report/{file}` (signed) | `routes/web.php` | Not in the new §8; see §3.4 | 2C.7 |
| `GET /attachments/{submission}/{filename}` | `routes/web.php` | Paths per source | 2C.6 |
| `throttle:odk-pull` | `routes/web.php`, `AppServiceProvider` | Per source | 2C.3 |
| Schedule: `PullSubmissionsJob(Scheduled)` every 10 min, only if `OdkConfig::isConfigured()` | `routes/console.php` | One pull per configured, enabled source; a nightly reconcile per source | 2C.3, 2C.5 |
| Wayfinder helpers | `resources/js/actions/App/Http/Controllers/Odk/*`, `…/OdkWebhookController.ts`, `…/Admin/AdminOdkController.ts`, `resources/js/routes/api/odk/*`, `resources/js/routes/webhooks/index.ts`, `resources/js/routes/admin/index.ts` | Regenerated with `make routes` | 2C.7 |

### 2.4 Commands and Makefile targets

| Item | Path | New spec | Step |
|---|---|---|---|
| `edqa:pull {--dry-run} {--limit=}` | `app/Console/Commands/Pull.php` | `edqa:pull {source}` or `--all` | 2C.3 |
| `edqa:backfill {--survey} {--from-page=} {--throttle=} {--dry-run}` | `app/Console/Commands/Backfill.php` | `edqa:backfill {source}`, one source at a time | 2C.3 |
| `edqa:backfill-report {--format=}` | `app/Console/Commands/BackfillReport.php` | By source, round, year, rule and form version | 2C.3 |
| `edqa:odk:check {--json}` | `app/Console/Commands/OdkCheck.php` | `edqa:source:check {source}` | 2C.4 |
| `edqa:odk-mock:generate` | `app/Console/Commands/GenerateOdkMock.php` | Keep; add a Kobo mock generator | 2C.4 |
| `edqa:attachments:fetch-pending` | `app/Console/Commands/FetchPendingAttachments.php` | Per source paths and host guard | 2C.6 |
| `edqa:reconcile {source}` | — (new) | | 2C.5 |
| `make e2e-odk`, `make odk-mock-up`, `make odk-mock-seed` | `Makefile`, `compose.e2e-odk.yml` | Add Kobo equivalents | 2C.4, 2C.7 |
| `odk-mock` service (profile `odk`) | `compose.dev.yml`, `docker/odk-mock/` (router, README) | Add a Kobo mock service | 2C.4 |

### 2.5 Config keys, env keys, disks and queues

| Item | Path | New spec | Step |
|---|---|---|---|
| `edqa.odk.*`: `url`, `email`, `password`, `project_id`, `form_id`, `page_size`, `timeouts.*`, `retry.*`, `token_refresh_minutes`, `allow_insecure_local`, `mock_path`, `pull.lock_seconds`, `pull.lock_wait_seconds`, `webhook.secret`, `webhook.tolerance_seconds`, `webhook.debounce_seconds`, `backfill.throttle_seconds`, `backfill.job_seconds` | `config/edqa.php` | `edqa.sources.{odk,kobo}.*`; shared settings (timeouts, retries, locks, throttle) move out of the ODK block | 2C.1 |
| `edqa_field_maps.default`, `edqa_field_maps.versions.{2026.1, 2025.2}` | `config/edqa_field_maps.php` | `maps.{name}` + `sources.{odk,kobo}.{version} => name` | 2C.2 |
| `ODK_CENTRAL_URL`, `ODK_CENTRAL_EMAIL`, `ODK_CENTRAL_PASSWORD`, `ODK_PROJECT_ID`, `ODK_FORM_ID`, `ODK_WEBHOOK_SECRET`, `ODK_PULL_PAGE_SIZE`, `ODK_BACKFILL_THROTTLE`, `ODK_ALLOW_INSECURE_LOCAL` | `.env.example`, `config/edqa.php` | Keep the ODK keys; add `KOBO_BASE_URL`, `KOBO_API_TOKEN`, `KOBO_ASSET_UID`, `KOBO_WEBHOOK_TOKEN`. Page size and throttle become per source or shared. **Server contract change: note it for `edqa-infra` (DEPLOY.md §1.3)** | 2C.1 (webhook token 2C.6) |
| Disk `odk` (`storage/app/odk`) | `config/filesystems.php` | `storage/app/attachments/{source}/{instance_id}/` | 2C.6 |
| Mock data `storage/app/odk-mock` | `config/edqa.php` (`mock_path`), `compose.dev.yml` | Add `storage/app/kobo-mock` | 2C.4 |
| Queues `default` (pull), `low` (backfill, attachments) | `app/Domain/Ingestion/Jobs/` | Unchanged names; jobs gain a source | 2C.3 |

### 2.6 Fixtures and test support

| Item | Path | Step |
|---|---|---|
| Raw ODK fixtures (21 broken, valid set) | `tests/Fixtures/odk/broken/`, `tests/Fixtures/odk/valid/`, `tests/Fixtures/generate.mjs`, `tests/Fixtures/README.md` | Keep; add `tests/Fixtures/kobo/` and envelope fixtures — 2C.2, 2C.4 |
| Parsed fixtures and expectations | `tests/Fixtures/parsed/broken/*.json`, `*.expected.json` | Still valid after the parser change (they are `ParsedSubmission`s); the expectations gain the source in some details — 2C.2, 2C.3 |
| `tests/Support/OdkPayload.php`, `FakeSubmissionSource.php`, `FixtureWorld.php`, `Rows.php` (`odk_*` rows) | `tests/Support/` | 2C.1–2C.3 |
| `submissionRow()`, `quarantineThrough()` | `tests/Pest.php` | Rows need a `source_id` — 2C.1 |

### 2.7 Tests that assume one source

| Test | Path | Step |
|---|---|---|
| Architecture rule "CLAUDE.md hard rule 1: ODK is the only data source" | `tests/Arch/ArchitectureTest.php` (line 90) | Rewrite for "sources only, through the normaliser" — 2C.2 |
| ODK client, check command, mock, API, fixtures | `tests/Feature/Ingestion/Odk/OdkCentralClientTest.php`, `OdkCheckCommandTest.php`, `OdkMockTest.php`, `tests/Feature/Ingestion/OdkApiTest.php`, `OdkFixturesTest.php` | 2C.3, 2C.4, 2C.7 |
| Pull, backfill, attachments, parser, processing | `tests/Feature/Ingestion/PullSubmissionsTest.php`, `BackfillTest.php`, `AttachmentsTest.php`, `SubmissionParserTest.php`, `SubmissionParserPropertyTest.php`, `ProcessParsedSubmissionTest.php` | 2C.2, 2C.3, 2C.6 |
| Field map, ODK config | `tests/Unit/Ingestion/FieldMapTest.php`, `OdkConfigTest.php` | 2C.1, 2C.2 |
| Tables, timezone, models, relationships, factories, audit | `tests/Feature/Database/IngestionTablesTest.php`, `TimezoneTest.php`, `tests/Feature/Domain/ModelsTest.php`, `RelationshipsTest.php`, `FactoriesTest.php`, `AuditTest.php` | 2C.1 |
| Validation actions and rules | `tests/Feature/Validation/Actions/CorrectAndReprocessTest.php`, `MarkSupersededTest.php`, `tests/Feature/Validation/Rules/ColumnDriftRuleTest.php`, `DuplicateAssessmentRuleTest.php` | 2C.2, 2C.3 |
| Admin, shell, Data issues, drawer, cascade, route guard | `tests/Feature/Admin/AdminOdkPageTest.php`, `AdminShellTest.php`, `AdminDataIssuesPageTest.php`, `tests/Feature/Dashboard/FacilityDetailTest.php`, `DashboardPagesTest.php`, `tests/Feature/Facilities/CascadeExportTest.php`, `tests/Feature/Auth/RouteGuardTest.php` (webhook route) | 2C.6, 2C.7 |
| Unit | `tests/Unit/Support/NormaliseTest.php` | — (comment only) |
| Vitest | `resources/js/components/AdminComponents.test.tsx`, `ShellComponents.test.tsx`, `DataIssues.test.tsx`, `FacilityDrawer.test.tsx`, `AssessmentCorrection.test.tsx` (ODK strings in fixtures) | 2C.7 |
| Playwright | `tests/e2e/odk-pull.spec.ts`, `odk-mock.spec.ts`, `admin.spec.ts` (page "ODK pull"), `audit.spec.ts` | 2C.7 |

### 2.8 UI: pages, components, types and strings

| Item | Path | Step |
|---|---|---|
| ODK pull page | `resources/js/pages/admin/odk.tsx`; `components/OdkConnectionPanel.tsx`, `PullNowPanel.tsx`, `PullHistoryPanel.tsx`, `BackfillPanel.tsx`, `CascadePanel.tsx` | Data sources page: one panel per source, Pull all, Reconcile, a source column in history, deletions-at-source list — 2C.7 |
| Shell | `components/SyncStatusBar.tsx`, `StaleIngestionBanner.tsx`, `layouts/AppShell.tsx`, `lib/pageMeta.ts`, `types/shell.ts` | Status per source — 2C.7 |
| Data issues | `pages/admin/data-issues.tsx`, `components/DataIssueDrawer.tsx` | Source filter; deep link to the submission on its source — 2C.6, 2C.7 |
| Facility drawer | `components/FacilityDetailView.tsx` (ODK submission section) | Source named — 2C.6, 2C.7 |
| Types | `types/admin.ts` (`OdkStatus`, `PullRun`, `PullHistoryRow`, `DataIssueRow.odk_url`), `types/dashboard.ts` (`odk` block) | 2C.7 |
| Strings: whole file | `lang/en/odk.php` (`errors`, `check`, `pull`, `webhook`, `attachments`, `mock`) | Split into shared + per source — 2C.3–2C.7 |
| Strings: admin | `lang/en/admin.php`: `tabs.odk` ("ODK pull"), the `odk` block ("Connection to ODK Central", …), 30 ODK mentions | 2C.7 |
| Strings: elsewhere | `lang/en/common.php` (`sync.not_configured`, `sync.not_connected`: "ODK Central is not configured…"); `lang/en/dashboard.php` (`attachment_status.pending/failed` "…from ODK…", `odk_title`, `odk_none`, `open_in_odk`, `odk_not_configured`); `lang/en/quarantine.php` (`superseded_note` "Superseded by ODK edit"); `lang/en/validation_rules.php` (`UNKNOWN_FORM_VERSION.why` "…version of the ODK form…"); `lang/en/backfill.php` (survey title, report `generated`, `totals`) | 2C.3, 2C.7 |
| Runbooks and docs | `docs/runbooks/odk-credentials-day.md`, `docs/odk-cascade-media.md`, `docker/odk-mock/README.md`, `docs/demo-script.md` | Add a Kobo credentials-day runbook; cascade doc for both — 2C.4, 2C.7 |

---

## 3. Where the code and the new documents disagree, outside the 2C steps

These come from the new documents themselves, not from Kobo. No 2C step fixes them, and some of
them are risky to build on.

### 3.1 Decision numbers collide, and 36 decisions are gone (blocking)

- The new ARCHITECTURE.md §11.1 holds D-01–D-16 and D-21–D-26 only. **D-17–D-20 and D-27–D-58
  are gone**, although **112 files** in the code, tests, CHANGELOG and docs cite D-27–D-58 by number.
- **The new D-21–D-26 reuse numbers that already had other meanings.** D-21 was Node 24, D-22
  asset precompression, D-23 fonts, D-24 hashed 2FA recovery codes, D-25 removing the starter UI,
  and D-26 the 2FA switch. D-24 and D-26 are still cited with their old meanings in 5 files.
  CONVENTION.md and the old CLAUDE.md also cite "D-26" for the 2FA switch.
- **Proposed fix:** restore the build-era register from git (`git show HEAD:ARCHITECTURE.md`
  holds up to D-56). D-57 and D-58 were only in the replaced working copy. Their text is
  summarised in CHANGELOG.md, and I can write them back in full. Then renumber the Kobo
  decisions **D-59–D-64**, keeping D-01's amendment pointing at the new number.

### 3.2 Open-question numbers collide, and many are gone (blocking)

- The new §11.4 lists Q-01–Q-11 and Q-15–Q-18. **Q-12–Q-14 and Q-19–Q-34 are gone** (git HEAD
  has up to Q-34), among them Q-25 (the choice map), cited in 9 files, and Q-29.
- **The new Q-15–Q-18 (Kobo) reuse numbers in use**: Q-15 the authoritative LGA and ward lists,
  Q-16 historical typed scores (cited in 6 files), Q-17 SMTP, Q-18 status values (cited in 3
  files).
- **Proposed fix:** restore Q-01–Q-34 and renumber the Kobo questions **Q-35–Q-38**.

### 3.3 The stack and build decisions have gone back to the pre-build baseline

| New document says | Code is | Decided by |
|---|---|---|
| Laravel 12, Inertia 2, React 18, Pest 3 (CLAUDE.md §2) | Laravel 13, Inertia 3, React 19, Pest 4 | old D-18 (lost) |
| "Fortify with mandatory 2FA", D-02 "2FA mandatory" | Mandatory unless `EDQA_REQUIRE_2FA=false` | old D-26 (renumbered away) |
| Build step 4: "one round" | Four demo rounds | build-era seeders |
| Build step 1: "CI green; images … pass Trivy" | CI and Trivy deferred | old §11.5 (section gone) |
| CLAUDE.md §8 commands: no `make test-slow`, `e2e`, `e2e-odk`, `e2e-issues`, `size`, `lighthouse`, `fresh`, `grants-check`, `prod-build`, `routes`, mock targets | All exist and are used | Phases 1–5 |

Proposed fix: restore those lines in CLAUDE.md §2, §6 and §8 alongside the Kobo additions.

### 3.4 The API, admin page and built-behaviour sections lost what was built

- **§8 is the original table.** It has none of these, all of which exist and are tested:
  - `POST /api/assessments/{id}/corrections` (D-58);
  - `POST /api/assessments/{id}/flags/{code}/override`;
  - `GET /api/quarantine/{record}`, `GET /api/facility-options` (D-57);
  - `GET /api/rescores/{batchId}`;
  - `GET`/`POST /api/scoring-rules`;
  - `POST /api/rounds`, `PATCH /api/rounds/{id}`, `POST /api/rounds/{id}/rescore`;
  - the signed cascade and backfill-report downloads;
  - `GET /attachments/{submission}/{filename}`.

  Its `{ action: correct|reject|override }` on `/api/quarantine/{id}/resolve` also contradicts
  the code, where `override` belongs to the assessment flag endpoint.
- **The "Built (…)" notes are gone** from §5, §6, §9.2 and §9.3: the pull mechanics (D-51), the
  backfill (D-53), the webhook (D-54), the admin shell (D-55), the ODK pull page (D-56), the Data
  issues state filter and bulk rule (D-57), and the drawer corrections (D-58). Rebuilding the
  Data sources page from the new §9.3 alone would drop behaviour that is tested today.
- Proposed fix: restore those notes from git HEAD (and D-57/D-58 from this session), then
  amend them for sources in 2C.7.

### 3.5 Where the choice map lives (changes numbers: needs an owner decision)

- **Code:** the `yes`/`no`/`na` → pass/fail/na map belongs to the **scoring rule version**
  (`RuleConfig::outcome()`, `choice_map`, Q-25). Changing it is a publish with a reason, it
  rescores, and closed rounds keep their pinned map (D-32).
- **New §5.1 and §5.5:** `choices` sit in the **field map**, per (source, version).
- These are not the same. A field-map change is not versioned, audited or pinned to rounds, so
  moving the map there would let a config edit change published scores without a rescore record.
  This changes numbers, so per CLAUDE.md §9 it should be an open question, not a guess.
- **Proposed new question:** "Choices per form version (field map) or per rule version (as
  built)?" My recommendation is to keep the map in the rule version. Field maps then only name
  the raw values a source sends, normalised to the shared `yes`/`no`/`na` before the rule
  version maps them.

### 3.6 Smaller differences to settle in the 2C step that touches them

| Topic | New document | Code | Where to settle |
|---|---|---|---|
| ODK webhook signature | HMAC-SHA256 over the raw body, timestamp within 5 min | HMAC over `"{timestamp}.{body}"`, headers `X-Edqa-Timestamp`, `X-Edqa-Signature` (D-54) | 2C.6: keep D-54's signed string (it covers the timestamp too) and say so |
| `source_syncs` | One `cursor_at` | Two cursors: submission date and `updatedAt` (`last_pulled_at`, `last_edit_at`; D-51) | 2C.1: keep both (ODK's `changedSince()` needs the second) |
| `pull_runs` columns | No `parsed`, `flagged` listed in §4 (§8 mentions parsed) | `parsed` (D-56), `flagged` | 2C.1: keep them |
| `quarantined_records` columns | No `parsed`, `corrections` | Both (D-08 overlay) | 2C.1: keep them |
| Field-map paths | `facility/facility_code` (slashes) | `facility.facility_code` (dots, nested ODK objects) | 2C.2: the envelope decides one form |
| Admin page names | "Data sources" | "ODK pull" (tab, page, e2e, strings) | 2C.7 |
| DEPLOY.md | Unchanged: no Kobo env keys, no Kobo egress, no second mock | | Needs a Kobo update before 2C.1 merges (env contract) |
| ACCESSIBILITY.md | Now required by CLAUDE.md for any UI work | Still not in the repository | Supply it before 2C.7 |

---

## 4. Not affected

These do not depend on the source and need no change:
- **Scoring:** `ScoreCalculator`, rule versions, rescore.
- **Aggregates:** `round_aggregates` and the aggregators, except the drawer and shell items above.
- **Validation rules:** the rules themselves, apart from the three whose details name the source.
- **Rounds, facilities, users, auth and the activity log.**
- **Assessment corrections (D-58).** These act on assessments, which stay source-neutral.
