# Runbook: the day ODK Central access arrives

The exact order of steps once the client hands over an ODK Central service account, from the
first connection to the scheduled pull running on its own. Each step says how to check it worked
and how to undo it. Steps a–g are run on **staging** first; production repeats them once staging
is signed off.

Commands are written for the dev stack (`make artisan c="…"`). On a server, run the same
`php artisan …` in the `app` container (DEPLOY.md). Nothing here writes to ODK Central: the
portal and the discovery tools only read (SECURITY.md §6).

| Step | Writes to the portal database? | Undo |
|---|---|---|
| a. `.env` and `edqa:odk:check` | No (a cached session token) | Clear the values |
| b. Discovery tools | No (local files in `discovery/`) | Delete `discovery/data/` |
| c. Field maps | No (a config file) | Revert the file |
| d. Dry run | No | Nothing to undo |
| e. Field-map iterations | No | Revert the file |
| f. Rounds, survey | Rounds only (audited) | Edit the window; delete in non-production |
| g. Backfill, report | Yes: submissions, assessments, quarantine | Truncate the ingestion tables (non-production), below |
| h. Scheduled pull | Yes, every ten minutes | Stop the scheduler |

---

## a. Fill `.env` and run `edqa:odk:check`

The account must be a **Project Viewer** on the one project and nothing more (SECURITY.md §6).

```
ODK_CENTRAL_URL=https://central.example.org     # https only; no trailing /v1
ODK_CENTRAL_EMAIL=svc-edqa@…
ODK_CENTRAL_PASSWORD=…
ODK_PROJECT_ID=7
ODK_FORM_ID=dqa                                 # the form's xmlFormId
# ODK_ALLOW_INSECURE_LOCAL must be absent on every server
```

```bash
make artisan c="config:clear"        # on a server the container caches config at start: restart it
make artisan c="edqa:odk:check"
```

**Check:** it prints `Connected as …`, the project and form names, and every published form
version. Note each version marked `field map: NO`; step c fixes them. If it fails:

- `not_configured` names the missing keys;
- `auth_credentials` / `auth_forbidden` means the email, password or role is wrong;
- `unreachable_*` points at the network, DNS or TLS;
- `unexpected_status` means the project or form ID is wrong.

**Undo:** remove the values and `config:clear`. The only thing stored is the encrypted session
token in the cache; `make artisan c="cache:forget edqa:odk:session"` drops it (or `cache:clear`).

## b. Run the Phase 0 discovery tools

These are the `discovery/` scripts (`discovery/README.md`). The names this runbook was planned
with map to them as follows:

- *odk_inventory* is `probe.py` + `form_versions.py`;
- *form_diff* is `field_diff.py`;
- *defect_scan* is `submission_profile.py` + `rule_preview.py`, with `facility_reconcile.py`.

They are read-only against Central, throttled, and never print the password or token.

```bash
cd discovery && python -m venv .venv && . .venv/bin/activate && pip install .
cp .env.example .env                                # the same five values as above
python probe.py                                     # out/probe.md: Central version, write permissions (must be none)
python form_versions.py                             # out/form_versions.md, data/forms/
python field_diff.py                                # out/field_diff.md, out/edqa_field_maps.draft.php
python submission_profile.py                        # out/submission_profile.md: volume per year, quarter, version
python facility_reconcile.py --registry data/hfr_export.xlsx
python rule_preview.py --registry data/hfr_export.xlsx --calendar-rounds
```

**Check:** `probe.md` shows no write permission; `form_versions.md` lists the same versions as
step a; `submission_profile.md` gives the backfill size; `rule_preview.md` gives the expected
quarantine by rule and year.

**Undo:** nothing reaches the portal. `discovery/data/` holds raw downloads (git-ignored, personal
data): delete it when done. Only `out/*.md` is committed, and it holds counts, not values.

## c. Write the real field maps from `form_diff`

Open `discovery/out/edqa_field_maps.draft.php` beside `config/edqa_field_maps.php`.

1. Make the current version the `default`, a **full** map: every field's dot path from the form
   XML.
2. Give every older version an **additive** entry: only the paths that differ, plus `renames`
   for scored questions named differently (`[name in this version => name in the default]`).
3. Check the scored questions match `scored_prefix_pattern`. If the real form names them
   differently, change the pattern; never rename in code.
4. Remove a `CONTRACT-ASSUMED` marker **only** for a path you have confirmed in the form XML.
   `field_diff.py` marks inferred ones `CONFIRM`; leave those marked until checked.
5. Record the versions in ARCHITECTURE.md §5.5 and close Q-02/Q-05 in §11.4.

```bash
make check                                         # the parser's golden tests still pass
make artisan c="edqa:odk:check"                    # every version now says field map: yes
```

**Undo:** `git checkout config/edqa_field_maps.php`. Nothing has been stored yet.

## d. `edqa:pull --dry-run --limit=500` and review the outcome by rule

```bash
make artisan c="edqa:pull --dry-run --limit=500"
```

It fetches up to 500 submissions from the start (the cursor is still empty), parses and
validates them in memory, and prints what a real pull would do, by rule. **It writes nothing:**
no submission, cursor, run row or audit entry.

**Read the table:**

- `UNKNOWN_FORM_VERSION`: a version with no field map. Back to step c.
- `ITEMS_INCOMPLETE` in bulk: usually a scored question the map misses (a rename), or an answer
  outside the rule version's `choice_map` (Q-25: is "Yes" with a capital in the real form?).
- `COLUMN_DRIFT`, `LGA_UNKNOWN`, `FACILITY_UNKNOWN`, `FACILITY_LGA_MISMATCH`, `SCORE_RANGE`,
  `ROUND_WINDOW`: the real data problems the portal exists to hold back. They are expected. Compare
  them with `rule_preview.md`.

**Undo:** nothing to undo.

## e. Fix the field maps until there is no `UNKNOWN_FORM_VERSION` or unexpected `ITEMS_INCOMPLETE`

Repeat c → d. Use `--limit` to sample the period of each older version. Stop when:

- `UNKNOWN_FORM_VERSION` is 0, unless a version is deliberately left unmapped: write that down
  in §11.4 with its submission count;
- every `ITEMS_INCOMPLETE` left is a genuine defect (a missing month slot, an answer the form did
  not allow), checked on a few examples.

**Undo:** revert the file. Still nothing is stored.

## f. Create the historical rounds, then `edqa:backfill --survey`

```bash
make artisan c="edqa:backfill --survey"
```

The survey reads every submission and writes nothing. It prints submissions per visit quarter
and form version, whether each version has a field map, each quarter's round, and the quarters
still without one, with their counts.

Create each historical round with the client's windows (Q-10), through the Rounds page or
`POST /api/rounds`. The round is created **open**. A visit in a quarter with no round is
quarantined under `ROUND_WINDOW`, never guessed into the nearest round. A quarter with a handful
of stray visits (a wrong device clock, years out) may be left without a round on purpose.

Run the survey again until it says **Every quarter has an open round**, or lists only the
quarters you chose to leave out. A round that is already closed would quarantine its visits as
"round closed": reopen it for the backfill, close it after.

**Undo:**

- A wrong window: change it (Rounds page, `PATCH /api/rounds/{id}`; audited).
- A round created by mistake, before any visit is bound to it, in a non-production environment
  only:
  `DELETE FROM rounds WHERE id = … AND NOT EXISTS (SELECT 1 FROM assessments WHERE round_id = rounds.id)`.
- In production a round is never deleted: leave it empty, or ask.

## g. Backfill out of hours, then `edqa:backfill-report`

```bash
make artisan c="edqa:backfill --dry-run"            # optional: the whole history, in memory
make artisan c="edqa:backfill"                      # pages of 500, 5 s apart (ODK_BACKFILL_THROTTLE)
make artisan c="edqa:attachments:fetch-pending"     # photos and traces, on the low queue
make artisan c="edqa:backfill-report"               # storage/app/exports/backfill-report-….md
make artisan c="edqa:backfill-report --format=csv"
```

Start in the evening: about 40,000 submissions is about 80 pages, measured in hours. Watch the
per-page line. It is safe to stop with Ctrl-C (the page in progress rolls back) and to run
`edqa:backfill` again, which resumes from the last committed page (`--from-page=N` overrides).
The scheduled pull may run alongside; overlap only shows up as "already stored".

**Check:**

- the run ends `Backfill finished: …`;
- in the report, each round's Pulled = Accepted + Flagged + Quarantined + Rejected (+ Not
  processed, which should be 0);
- the quarantine by rule and year looks like `rule_preview.md`;
- the dashboard shows the historical rounds.

Then close each historical round (Rounds page): this pins its rule version and snapshots its
aggregates.

**Undo (non-production only):** truncate the ingestion tables (below), then start again from f.
In production nothing is truncated: a wrong field map is fixed in the map and the affected
submissions reprocessed from their stored payloads (§5.7, deferred: until it exists, ask before
the production backfill).

## h. Switch on the scheduled pull

The schedule entry is registered only when ODK is configured (`routes/console.php`): with step a
done, the scheduler runs `PullSubmissionsJob` every ten minutes. Make sure the server's
`scheduler` role is running (DEPLOY.md; locally
`docker compose -f compose.dev.yml --profile scheduler up -d scheduler`) and a worker serves the
`default` queue.

**Check:**

- within ten minutes the sync status bar reads `Last pull N min ago`;
- `GET /api/odk/status` shows `reachable.ok: true` and a moving `last_pulled_at`;
- the heartbeat URL (`HEARTBEAT_ODK_PULL_URL`, if set) is pinged.

**Undo:** stop the scheduler role (`docker compose stop scheduler`). Pulls that already ran
stay; "Pull now" still works on request. To stop every pull, remove the ODK values from `.env`
and restart: the bar says "not configured".

---

## Truncating the ingestion tables (non-production only)

This removes **everything** ODK brought in, and nothing else. Users, LGAs, wards, facilities,
rounds, scoring rule versions and plan actions stay. Use it on staging or a developer machine
to start the backfill again. **Never in production.**

Run it as the migrator role (`edqa_migrator`): the application role `edqa_app` has no
`TRUNCATE` (SECURITY.md §9). The shell guard refuses anywhere `APP_ENV` is not `local` or
`staging`. Check the environment and the database name before you press Enter.

```bash
case "$APP_ENV" in local|staging) ;; *) echo "Refusing: APP_ENV=$APP_ENV"; exit 1 ;; esac
psql -U edqa_migrator -d "$DB_DATABASE" -v ON_ERROR_STOP=1 -f truncate-ingestion.sql
```

`truncate-ingestion.sql`:

```sql
BEGIN;

-- One statement, so every foreign key between these tables is satisfied at once.
TRUNCATE
  odk_attachments,        -- -> submissions
  quarantined_records,    -- -> submissions, rounds
  assessment_scores,      -- -> assessments
  item_responses,         -- -> assessments
  assessments,            -- -> submissions, rounds, facilities
  submissions,            -- self-reference superseded_by_id
  odk_pull_runs,
  odk_form_syncs          -- the cursors and the backfill checkpoint
RESTART IDENTITY;

COMMIT;

SELECT refresh_round_aggregates();   -- the dashboard's view, now empty of them
```

Then:

```bash
rm -rf storage/app/odk/*                 # fetched attachments (the private odk disk)
make artisan c="cache:clear"             # the dashboard's round cache and the ODK session token
```

What stays, on purpose:

- `activity_log`. The audit log is append-only even for the migrator's tools. Its entries for
  the removed rows (corrections, overrides, edits) remain as history.
- `storage/app/exports/`. Old backfill reports; delete them by hand if they would confuse.
- Rounds. Delete those created for the backfill as in step f, if needed.
