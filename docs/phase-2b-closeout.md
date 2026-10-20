# Phase 2B close-out: ODK ingestion (v0.5.0)

Build step 8 of `CLAUDE.md` §6: *ODK client, field maps, pull job, lock, idempotency, edits,
backfill, attachments, `odk_pull_runs`. Acceptance: real submission lands end to end; old form
version parses; broken one quarantines.*

The client's ODK Central is not available yet, so "real" here means the local mock Central
(ARCHITECTURE.md §5.8), which serves what Central serves through the same `OdkCentralSource`. The
first run against the real server follows `docs/runbooks/odk-credentials-day.md`.

Run on 2026-10-20 on the dev stack. The dev database already held the demo seed and step 6's
backfill of 2,940 mock submissions (2024 Q1 – 2025 Q2).

## 1. End to end against the mock

```
$ make odk-mock-seed n=500
Wrote 460 valid, 20 to be flagged and 10 to be quarantined, plus 10 edits (staged) and 2 photo(s), to /var/www/html/storage/app/odk-mock.

$ make artisan c="edqa:odk:check"
ODK Central
Server: http://odk-mock:8080, project 1, form dqa
Connected as eDQA portal (mock). Project "Kaduna DQA (mock)", form "Data Quality Assessment (mock)", current version 2026.1.
Published form versions (2):
  2026.1  published 2026-01-05T09:00:00+00:00  field map: yes
  2025.2  published 2025-07-05T09:00:00+00:00  field map: yes

$ make artisan c="edqa:pull --dry-run"
Dry run: nothing was written (no submission, cursor or run row).
Fetched 200; 17 already stored; of the rest 168 would be accepted, 10 flagged and 5 quarantined.
+-----------------------+----------+-------------+
| Rule                  | Severity | Submissions |
+-----------------------+----------+-------------+
| ALL_PERFECT           | soft     | 4           |
| FACILITY_LGA_MISMATCH | hard     | 1           |
| FACILITY_UNKNOWN      | hard     | 1           |
| ITEMS_INCOMPLETE      | hard     | 1           |
| LGA_UNKNOWN           | hard     | 1           |
| SCORE_JUMP            | soft     | 1           |
| SCORE_RANGE           | hard     | 1           |
| VISIT_TOO_SHORT       | soft     | 5           |
+-----------------------+----------+-------------+

$ make artisan c="edqa:pull"
Pull succeeded: fetched 489, 46 already stored, 403 accepted, 31 flagged, 9 quarantined.     (92 s)

$ make artisan c="edqa:attachments:fetch-pending --sync"
Fetched attachments for 2 submission(s); for 0 of them ODK could not deliver every file, now marked failed.

$ make artisan c="edqa:odk-mock:generate --apply-edits" && make artisan c="edqa:pull"
Edits applied: the next pull sees the edited versions.
Pull succeeded: fetched 11, 1 already stored, 10 accepted, 0 flagged, 0 quarantined.
  → 10 submissions superseded, 10 assessments updated in place (10 audit entries), facilities visited unchanged
```

The mock's settings were passed as one-off environment variables (`ODK_CENTRAL_URL=http://odk-mock:8080`,
`ODK_ALLOW_INSECURE_LOCAL=true`, …); `.env` was not changed.

**"Already stored".** The generator's IDs come from its seed (2026), which step 6's data also used,
so some new mock rows reuse instance IDs already stored. The pull drops them as duplicates, as it
must. That is also why one of the ten deliberately broken rows was not stored, and nine were
quarantined.

**The dashboard shows the new data.** Facilities visited in Q2 2026 went from 439 to 873
(439 + 403 + 31), and Lere from 22 to 48. `round_aggregates` refreshed on the worker 60 s after
the pull (1 min 9 s on this machine). Then, with the figures read from the database:

```
$ docker compose -f compose.dev.yml --profile e2e run --rm -e E2E_ODK_VISITED=873 -e E2E_ODK_LGA=Lere \
    -e E2E_ODK_LGA_COUNT=48 -e E2E_ODK_QUARANTINED=… playwright tests/e2e/odk-mock.spec.ts --project=desktop
  ✓  1 [setup] › tests/e2e/auth.setup.ts:11:1 › sign in as the e2e administrator (15.1s)
  ✓  2 [desktop] › tests/e2e/odk-mock.spec.ts › Data pulled from the mock ODK Central › Home counts the pulled visits (5.6s)
  ✓  3 [desktop] › tests/e2e/odk-mock.spec.ts › … › the LGA view counts them, in total and for an LGA with new visits (2.4s)
  ✓  4 [desktop] › tests/e2e/odk-mock.spec.ts › … › the broken ones are in quarantine, through GET /api/quarantine, each with its rules (1.8s)
  4 passed (30.1s)
```

**The broken ones via `GET /api/quarantine`** (spec 4: each instance ID on page 1 of
`?status=open`, with exactly these failure codes):

| Instance | Rule |
|---|---|
| uuid:0e6fafd2-…-177d5d12fb52 | ITEMS_INCOMPLETE |
| uuid:bc419739-…-e2ea1765dbfc | FACILITY_LGA_MISMATCH |
| uuid:cd3ec8c0-…-3efcd0f5cb35 | LGA_UNKNOWN |
| uuid:af446f66-…-c49cdba5b77e | SCORE_RANGE |
| uuid:e43e754b-…-e2899cee81dd | FACILITY_UNKNOWN |
| uuid:1faa3805-…-2e17c0e1b11b | COLUMN_DRIFT |
| uuid:e09474f3-…-7431656329fc | END_BEFORE_START |
| uuid:bd28bf1b-…-c27a82cadd23 | ITEMS_INCOMPLETE |
| uuid:e0fcee32-…-8923de036b12 | LGA_UNKNOWN |

The older form version (2025.2) was proven end to end in step 6's backfill: 972 submissions in
2024 Q1–Q2 parsed through its additive map, with the same outcomes as 2026.1.

## 2. Test results

| Suite | Result |
|---|---|
| `make check` | Pint: 512 files pass. Larastan L6: no errors. Pest: 1,317 passed, 1 failed (78,824 assertions, 511 s). The failure was a second-boundary race in a new test (`CascadeExportTest`: an expiry compared to the second); fixed with `freezeSecond()` and passed three runs in a row (8/8). |
| `make e2e` | 119 passed, 2 failed, 14 skipped (6.7 min). The 2 failures were one Home test on two viewports expecting exactly the demo seed's four rounds. The dev data now has ten (step 6's backfill), and the trend correctly showed all ten. The test now compares the table with `GET /api/rounds`: 9/9 passed. The skips include the mock spec's three tests on two viewports, which run only with figures (above). |
| `make test-slow` | 2 passed (184 assertions, 920 s): every dashboard page and API within the p95 budget on the ~44k performance dataset; rescore of 2,200 assessments in 73 s (budget 5 min). |
| Vitest, typecheck, lint | 106 passed (step 7); typecheck and lint pass after the close-out's spec changes. |

The e2e and Lighthouse runs use production-like PHP on the dev stack (opcache without timestamp
checks, config and routes cached), restored afterwards. **Never run Pest with the config cached:**
it would read the dev database's settings.

## 3. Exit gate evidence

| Gate | Evidence |
|---|---|
| **Golden parser tests** | `OdkFixturesTest`: 23 raw ODK entries, each parses into exactly its parsed fixture and ends with the outcome 2A expects through the real pipeline; `SubmissionParserPropertyTest`: never throws on 500 randomly mangled payloads. `OdkMockTest`: an older version (2025.2) written through its own map parses back. |
| **Idempotency** | `PullSubmissionsTest`: "inserts nothing when the same pull runs again", "never loses rows that share the cursor timestamp, even a whole page of them". `BackfillTest`: "stores nothing twice when it overlaps the incremental pull, either way round". Live: 46 already-stored rows dropped above; the pull after the backfill read 1 row, 1 already stored. |
| **Resume** | `PullSubmissionsTest`: "replays a page that crashed, with no duplicates and the cursor where the last good page left it", "leaves nothing of a page that crashed half way: one transaction". `BackfillTest`: "resumes at the checkpoint after a crash at page N, with no duplicates", "starts at --from-page". |
| **Edits** | `PullSubmissionsTest`: "applies an ODK edit: the original is superseded and its assessment updated in place, audited", "resolves the quarantined original when its edit arrives (fix at source)". `OdkMockTest`: the mock's edit applied in place, nothing twice. Live: 10 edits → 10 superseded, 10 audited updates, visited unchanged. |
| **Dry run writes nothing** | `PullSubmissionsTest`: "dry-runs …, and writes nothing" (row counts of every table, and the sync row, identical). `BackfillTest`: "dry-runs the whole history without writing anything", "surveys … without writing anything". |
| **Attachments secured** | `AttachmentsTest`: allowed types by their bytes; disallowed bytes rejected and deleted (4 cases); over the cap rejected; unsafe names refused without a download (6 cases); never fetched twice; streamed with `nosniff` and a private cache, each view audited; 404 for 9 traversal and lookup cases; signed out refused. Live: both mock photos fetched as `image/jpeg` to the private disk. |
| **Webhook writes nothing** | `OdkApiTest`: "never treats the body as data: a signed body full of submissions writes nothing" (every table's row count unchanged); 401 for missing, invalid and stale signatures (9 cases); 404 without a secret; one pull a minute at most; rate limited. |

## 4. Not proven here (needs the client's Central)

The field maps (`CONTRACT-ASSUMED`), `$orderby` on `updatedAt` (Q-32), a stable feed for the
backfill's resume (Q-33), a webhook sender (Q-34). The deferred list is ARCHITECTURE.md §5.9.
