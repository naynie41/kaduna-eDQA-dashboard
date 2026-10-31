# Runbook: the day source access arrives

The exact order of steps once the client hands over access to a field-collection source, from the
first connection to the scheduled pull running on its own. There is one section per source: **ODK
Central** (part A) and **KoboToolbox** (part B). They are independent: do whichever arrives first,
and the other when it does. When both are live, finish with part C, the duplicate check.

Each step says how to check it worked and how to undo it. Every step is run on **staging** first;
production repeats them once staging is signed off.

Commands are written for the dev stack (`make artisan c="…"`). On a server, run the same
`php artisan …` in the `app` container (DEPLOY.md). **Nothing here writes to a source:** the portal
and the discovery tools only read (SECURITY.md §6).

| Step | Writes to the portal database? | Undo |
|---|---|---|
| 1. `.env` and `edqa:source:check` | No (ODK: a cached session token) | Clear the values |
| 2. Discovery tools | No (local files in `discovery/`) | Delete `discovery/data/` |
| 3. Field maps | No (a config file) | Revert the file |
| 4. Dry run | No | Nothing to undo |
| 5. Field-map iterations | No | Revert the file |
| 6. Rounds, survey | Rounds only (audited) | Edit the window; delete in non-production |
| 7. Backfill, report | Yes: submissions, assessments, quarantine | Truncate the ingestion tables (non-production), below |
| 8. Scheduled pull | Yes, every ten minutes, plus the nightly reconcile | Stop the scheduler, or switch the source off |

**Backfill one source at a time.** Never run two backfills together: each holds its source's
backfill lock, and two at once would double the load on the database for no gain. Finish the
first source's backfill and its report before starting the second.

---

## Part A: ODK Central

### A1. Fill `.env` and run `edqa:source:check odk`

The account must be a **Project Viewer** on the one project and nothing more (SECURITY.md §6).

```
ODK_CENTRAL_URL=https://central.example.org     # https only; no trailing /v1
ODK_CENTRAL_EMAIL=svc-edqa@…
ODK_CENTRAL_PASSWORD=…
ODK_PROJECT_ID=7
ODK_FORM_ID=dqa                                 # the form's xmlFormId
ODK_WEBHOOK_SECRET=…                            # optional: the webhook (SECURITY.md §6)
# ODK_ALLOW_INSECURE_LOCAL must be absent on every server
```

```bash
make artisan c="config:clear"         # on a server the container caches config at start: restart it
make artisan c="edqa:sources:sync"    # once: makes sure the source rows exist
make artisan c="edqa:source:check odk"
```

**Check:** it prints `Connected as …`, the project and form names, and every published form
version. Note each version marked `field map: NO`: step A3 fixes them. If it fails, the status
in brackets says why:

- `not_configured`: the keys it names are missing;
- `auth_failed`: the email, password or role is wrong;
- `unreachable`: the network, DNS or TLS;
- `unexpected_response`: usually the project or form ID is wrong.

**Undo:** remove the values and `config:clear`. The only thing stored is the encrypted session
token in the cache; `make artisan c="cache:forget edqa:odk:session"` drops it (or `cache:clear`).

### A2. Run the discovery tools

These are the `discovery/` scripts (`discovery/README.md`). The names this runbook was planned
with map to them as follows:

- *odk_inventory*: `probe.py` and `form_versions.py`;
- *form_diff*: `field_diff.py`;
- *defect_scan*: `submission_profile.py` and `rule_preview.py`, with `facility_reconcile.py`.

They are read-only against Central, throttled, and never print the password or token.

```bash
cd discovery && python -m venv .venv && . .venv/bin/activate && pip install .
cp .env.example .env                                # the ODK values as above
python probe.py                                     # out/probe.md: Central version, write permissions (must be none)
python form_versions.py                             # out/form_versions.md, data/forms/
python field_diff.py                                # out/field_diff.md, out/edqa_field_maps.draft.php
python submission_profile.py                        # out/submission_profile.md: volume per year, quarter, version
python facility_reconcile.py --registry data/hfr_export.xlsx
python rule_preview.py --registry data/hfr_export.xlsx --calendar-rounds
```

**Check:**
- `probe.md` shows no write permission;
- `form_versions.md` lists the same versions as step A1;
- `submission_profile.md` gives the backfill size;
- `rule_preview.md` gives the expected quarantine, by rule and year.

**Undo:** nothing reaches the portal. `discovery/data/` holds raw downloads (git-ignored, personal
data): delete it when done. Only `out/*.md` is committed, and it holds counts, not values.

### A3. Write the field maps from `field_diff`

Open `discovery/out/edqa_field_maps.draft.php` beside `config/edqa_field_maps.php`.

1. Write the current form as a **full** map under `maps`, with every field's path from the form
   XML.
2. Give every older form an **additive** map (`extends`) with only the paths that differ, plus
   `renames` for scored questions named differently (`[name in this form => name in the base]`).
3. Point each ODK version at its map under `sources.odk` (`'2026.1' => 'xls-2026.1'`, D-63).
4. Check that the scored questions match `scored_prefix_pattern`. If the real form names them
   differently, change the pattern; never rename in code.
5. Remove a `CONTRACT-ASSUMED` marker **only** for a path you have confirmed in the form XML.
   `field_diff.py` marks inferred ones `CONFIRM`; leave those until checked.
6. Record the versions in ARCHITECTURE.md §5.5.

```bash
make check                                  # the parser's golden tests still pass
make artisan c="edqa:source:check odk"      # every version now says field map: yes
```

**Undo:** `git checkout config/edqa_field_maps.php`. Nothing has been stored yet.

### A4. Dry run: `edqa:pull odk --dry-run --limit=500`

```bash
make artisan c="edqa:pull odk --dry-run --limit=500"
```

It fetches up to 500 submissions from the start (the cursor is still empty), parses and
validates them in memory, and prints what a real pull would do, by rule. **It writes nothing:**
no submission, cursor, run row or audit entry.

**Read the table:**

- `UNKNOWN_FORM_VERSION`: a version with no field map. Back to step A3.
- `ITEMS_INCOMPLETE` in bulk: usually a scored question the map misses (a rename), or an answer
  outside the rule version's `choice_map` (is "Yes" with a capital in the real form?).
- `COLUMN_DRIFT`, `LGA_UNKNOWN`, `FACILITY_UNKNOWN`, `FACILITY_LGA_MISMATCH`, `SCORE_RANGE`,
  `ROUND_WINDOW`: the real data problems the portal exists to hold back, and they are expected.
  Compare them with `rule_preview.md`.

**Undo:** nothing to undo.

### A5. Fix the maps until the dry run is clean

Repeat A3 and A4. Use `--limit` to sample the period of each older version. Stop when:

- `UNKNOWN_FORM_VERSION` is 0, unless a version is deliberately left unmapped. Write that down in
  ARCHITECTURE.md §11.4, with its submission count.
- Every `ITEMS_INCOMPLETE` left is a genuine defect (a missing month slot, an answer the form did
  not allow), checked on a few examples.

**Undo:** revert the file. Still nothing is stored.

### A6. Create the historical rounds, then `edqa:backfill odk --survey`

```bash
make artisan c="edqa:backfill odk --survey"
```

The survey reads every submission and writes nothing. It prints:
- the submissions per visit quarter and form version;
- whether each version has a field map;
- each quarter's round, and the quarters still without one, with their counts.

Create each historical round with the client's windows, through the Rounds page or
`POST /api/rounds`. A round is created **open**.
- A visit in a quarter with no round is quarantined under `ROUND_WINDOW`, never guessed into the
  nearest round.
- A quarter with a handful of stray visits (a wrong device clock, years out) may be left without
  a round on purpose.

Run the survey again until it says **Every quarter has an open round**, or lists only the quarters
you chose to leave out. A round that is already closed would quarantine its visits as "round
closed": reopen it for the backfill, and close it after.

**Undo:**

- A wrong window: change it (Rounds page or `PATCH /api/rounds/{id}`; audited).
- A round created by mistake, before any visit is bound to it, in a non-production environment
  only:
  `DELETE FROM rounds WHERE id = … AND NOT EXISTS (SELECT 1 FROM assessments WHERE round_id = rounds.id)`.
- In production a round is never deleted: leave it empty, or ask.

### A7. Backfill out of hours, then `edqa:backfill-report`

```bash
make artisan c="edqa:backfill odk --dry-run"        # optional: the whole history, in memory
make artisan c="edqa:backfill odk"                  # pages of 500, 5 s apart (ODK_BACKFILL_THROTTLE)
make artisan c="edqa:attachments:fetch-pending"     # photos and traces, on the low queue
make artisan c="edqa:backfill-report"               # storage/app/exports/backfill-report-….md
make artisan c="edqa:backfill-report --format=csv"
```

Start in the evening: about 40,000 submissions is about 80 pages, which takes hours. Watch the
per-page line.
- It is safe to stop with Ctrl-C: the page in progress rolls back.
- `edqa:backfill odk` run again resumes from the last committed page (`--from-page=N` overrides).
- The scheduled pull may run alongside. Overlap only shows up as "already stored".

**Check:**

- the run ends `Backfill finished: …`;
- in the report, each round's Pulled = Accepted + Flagged + Quarantined + Rejected (+ Not
  processed, which should be 0);
- the quarantine by rule and year looks like `rule_preview.md`;
- the dashboard shows the historical rounds.

Then close each historical round on the Rounds page. That pins its rule version and snapshots its
aggregates.

**Undo (non-production only):** truncate the ingestion tables (below), then start again from A6.
In production nothing is truncated. A wrong field map is fixed in the map, and the affected
submissions are reprocessed from their stored payloads (§5.7).

### A8. Switch on the scheduled pull

The schedule (`routes/console.php`) registers each **configured** source:
- `edqa:pull:odk` every ten minutes;
- `edqa:reconcile:odk` nightly at `edqa.pull.reconcile_at` (02:30).

Whether the source is switched on (`ODK_ENABLED`, and its row's `is_enabled`) is checked when each
run is due. Make sure the server's `scheduler` role is running (DEPLOY.md; locally
`docker compose -f compose.dev.yml --profile scheduler up -d scheduler`) and that a worker serves
the `default` queue.

**Check:**

- within ten minutes the status bar reads `ODK Central: last pull N min ago`;
- the Data sources page shows ODK Central **Connected**, with a recent last success;
- `GET /api/sources` shows ODK Central with `reachable.ok: true`, `status.state: healthy` and a
  moving `cursor.last_pulled_at`;
- the heartbeat URL (`HEARTBEAT_PULL_ODK_URL`, if set) is pinged.

**Undo:** set `ODK_ENABLED=false` and restart. Pulls that already ran stay, and the status bar
says "switched off". To stop every source's schedule at once, stop the scheduler role
(`docker compose stop scheduler`).

---

## Part B: KoboToolbox

### B1. Fill `.env` and run `edqa:source:check kobo`

Use an API token of a **dedicated account** that can only view the one asset's submissions, never
an owner's token (SECURITY.md §6). The asset uid is in the project's URL
(`…/#/forms/aBcD…/summary`).

```
KOBO_BASE_URL=https://kf.kobotoolbox.org        # or eu.kobotoolbox.org, or the self-hosted server; https only
KOBO_API_TOKEN=…
KOBO_ASSET_UID=aBcD…
KOBO_WEBHOOK_TOKEN=…                            # optional: at least 32 random characters (SECURITY.md §6)
# KOBO_ALLOW_INSECURE_LOCAL must be absent on every server
```

```bash
make artisan c="config:clear"
make artisan c="edqa:sources:sync"
make artisan c="edqa:source:check kobo"
```

**Check:** it prints `Connected as …` (the account), the asset's name and current deployed
version, and every deployed version with `field map: yes/NO`. If it fails, the status in brackets
says why:

- `not_configured`: the keys it names are missing; `KOBO_BASE_URL (https)` means the URL is not
  https;
- `auth_failed`: the token is wrong, or the account cannot view the asset;
- `unreachable`: the network, DNS or TLS;
- `unexpected_response`: usually a wrong asset uid.

Later, while pulling, `unsafe_url` on an attachment means that a link in a Kobo payload pointed
off the configured server, or was not https. The SSRF guard (SECURITY.md §6) refused it, and
nothing was fetched.

**Undo:** remove the values and `config:clear`. Nothing is stored: Kobo has no session.

### B2. Run the discovery tools for Kobo

The Kobo tools sit beside the ODK ones (`discovery/README.md`, "KoboToolbox"). They are read-only
(GET only, enforced in `kobo.py`), throttled, and never print the token. Add to `discovery/.env`:

```
KOBO_BASE_URL=…
KOBO_API_TOKEN=…
KOBO_ASSET_UID=…
```

```bash
python kobo_inventory.py                      # out/kobo_inventory.md: the asset, deployed versions, submissions per quarter
python kobo_forms.py                          # data/kobo/forms/<version uid>.xlsx: each deployed version as an XLSForm
python field_diff.py --source kobo            # out/kobo_field_diff.md, out/kobo_edqa_field_maps.draft.php
python rule_preview.py --source kobo --registry data/hfr_export.xlsx --calendar-rounds
```

Each tool takes `--offline fixtures/kobo` to run on the saved example responses instead, which
is a way to rehearse without credentials.

**Check:**
- `kobo_inventory.md` lists the same deployed versions as step B1, and the submissions per
  quarter (the backfill size);
- it names any submission carrying a version that is not deployed;
- `kobo_rule_preview.md` gives the expected quarantine, by rule and year.

**Undo:** delete `discovery/data/kobo/`. Only `out/*.md` is committed.

### B3. Write the Kobo field maps

Kobo labels each version with its deployment uid (`__version__`), not the XLSForm's version
string. One XLSForm deployed on both platforms uses **one** map (D-63).

1. For each deployed version, find which XLSForm it deploys. `kobo_field_diff.md` lists each
   version's questions, and the XLSForm's `version` setting tells you which ODK form version it is.
2. If it is a form already mapped for ODK, point the uid at that map under `sources.kobo`:
   `'aB12…' => 'xls-2026.1'`.
3. If it is a form only Kobo has, write its map under `maps` as in A3, then point the uid at it.
4. Paths are as the Kobo row names them (`facility/facility_code`), written with dots in the map
   (`facility.facility_code`). The normaliser turns Kobo's flat `group/question` keys into the
   same nested answers as ODK's, so one map reads both.

```bash
make check
make artisan c="edqa:source:check kobo"       # every deployed version now says field map: yes
```

**Undo:** `git checkout config/edqa_field_maps.php`.

**The real form (done 31 Oct 2026, D-65).** It does not follow the contract, so the scored
items come from **item rules** generated from the form's own score formulas, not a renames map:

```bash
python kobo_forms.py && python kobo_item_rules.py     # writes out/edqa_item_rules.kobo.draft.php
cp out/edqa_item_rules.kobo.draft.php ../config/edqa_item_rules.php   # after reviewing out/kobo_item_rules.md: 0 terms not read
```

Re-run both whenever the form is redeployed, and add the new deployment uid under `sources.kobo`.

**Facilities (D-72).** `kobo_item_rules.py` also writes `data/forms/kobo/facility_choices.csv`:
the form's slugs, with their names, wards and LGAs, and an empty `facility_code` column. Fill in
the codes (`facility_reconcile.py` helps), have the client sign it off, then load it:

```bash
make artisan c="edqa:facilities:source-refs kobo /path/facility_refs.csv --dry-run"   # checks only
make artisan c="edqa:facilities:source-refs kobo /path/facility_refs.csv"
```

It needs the columns `ref,facility_code`. One bad row refuses the whole file. Until it is loaded,
every Kobo submission quarantines as `FACILITY_UNKNOWN`.

**Round windows.** A Kobo visit reviews a month that has already passed: a visit in October can
review July. Set each round's window to cover when its visits happen, not only the quarter's
months, or `ROUND_WINDOW` holds them.

### B4. Dry run: `edqa:pull kobo --dry-run --limit=500`

The same as A4, for Kobo. It writes nothing. Read the table as in A4.

### B5. Fix the maps until the dry run is clean

As in A5. A Kobo-only difference to look for: a question whose choices were edited between
deployments, which shows as `ITEMS_INCOMPLETE` only for the rows of that uid.

### B6. Rounds, then `edqa:backfill kobo --survey`

The rounds are shared by both sources: a quarter that already has its round for ODK needs nothing
more. Run the survey and create only the rounds still missing, as in A6.

```bash
make artisan c="edqa:backfill kobo --survey"
```

### B7. Backfill Kobo out of hours, after ODK's has finished

```bash
make artisan c="edqa:backfill kobo --dry-run"       # optional
make artisan c="edqa:backfill kobo"                 # pages of 500, 5 s apart (KOBO_BACKFILL_THROTTLE)
make artisan c="edqa:attachments:fetch-pending"
make artisan c="edqa:backfill-report"
```

**Never while ODK's backfill is running.** The report covers both sources; its "Duplicates
between the sources" section is part C's evidence.

**Check:** as in A7, now per source. Then close any historical round still open.

**Undo (non-production only):** as in A7.

### B8. Switch on Kobo's scheduled pull

The same schedule as A8: `edqa:pull:kobo` every ten minutes and `edqa:reconcile:kobo` nightly.
Kobo does not update `_submission_time` when a submission is edited, so **the nightly reconcile is
what finds Kobo's edits** (D-61). Keep it on.

If the webhook is used, add a REST Service on the Kobo project:
- the URL is `https://<portal>/webhooks/kobo`;
- a custom header `X-EDQA-Webhook-Token` carries the `KOBO_WEBHOOK_TOKEN` value.

The webhook only queues a pull; the schedule keeps running without it.

**Check:** as in A8, for KoboToolbox (`HEARTBEAT_PULL_KOBO_URL`).

**Undo:** `KOBO_ENABLED=false` and restart; the bar says "KoboToolbox is switched off".

---

## Part C: both sources live, the duplicate check

Do this once both sources have backfilled and pulled on schedule for a day. A visit can reach the
portal twice:

- **Copied between servers**, with the same instance ID. The second source to send it counts it
  as a `cross_source_duplicate`, and it is never stored twice (D-60).
- **Entered again on the other tool**, with a new instance ID. One assessment is accepted. The
  other is quarantined as `DUPLICATE_ASSESSMENT`, and its detail names both sources.

```bash
make artisan c="edqa:backfill-report"   # "Duplicates between the sources": both kinds, per round
```

**Check:**

1. On the Data sources page, the pull history shows the "From another source" column. Its total
   should match the report's cross-source count. A steady trickle there means submissions are
   being copied between servers: ask the client whether that is intended.
2. Open Data issues, rule `DUPLICATE_ASSESSMENT`, source "All sources". Each record shows both
   sides: the accepted assessment and its source, then this submission and its source. Resolve
   each one with the client: reject the copy, or correct it if it was a different facility.
3. In Monitor, "Assessments by source" for the latest round should add up to the round's
   facilities visited. Neither source should be missing a block of LGAs it is known to cover.
4. After resolving, no open `DUPLICATE_ASSESSMENT` record should name both sources:

   ```sql
   SELECT count(*) FROM quarantined_records
   WHERE status = 'open'
     AND failures @> '[{"code": "DUPLICATE_ASSESSMENT"}]'::jsonb;
   ```

---

## Truncating the ingestion tables (non-production only)

This removes **everything** the sources brought in, and nothing else. Users, LGAs, wards,
facilities, rounds, scoring rule versions, plan actions and the `submission_sources` rows stay. Use
it on staging or a developer machine to start a backfill again. **Never in production.**

Run it as the migrator role (`edqa_migrator`): the application role `edqa_app` has no `TRUNCATE`
(SECURITY.md §9). The shell guard refuses anywhere `APP_ENV` is not `local` or `staging`. Check the
environment and the database name before you press Enter.

```bash
case "$APP_ENV" in local|staging) ;; *) echo "Refusing: APP_ENV=$APP_ENV"; exit 1 ;; esac
psql -U edqa_migrator -d "$DB_DATABASE" -v ON_ERROR_STOP=1 -f truncate-ingestion.sql
```

`truncate-ingestion.sql`:

```sql
BEGIN;

-- One statement, so every foreign key between these tables is satisfied at once.
TRUNCATE
  submission_attachments, -- -> submissions
  quarantined_records,    -- -> submissions, rounds
  assessment_scores,      -- -> assessments
  item_responses,         -- -> assessments
  assessments,            -- -> submissions, rounds, facilities
  submissions,            -- self-reference superseded_by_id
  pull_runs,
  source_syncs            -- the cursors and the backfill checkpoints, per source
RESTART IDENTITY;

COMMIT;

SELECT refresh_round_aggregates();   -- the dashboard's view, now empty of them
```

Then:

```bash
rm -rf storage/app/attachments/*         # fetched attachments (the private attachments disk)
make artisan c="cache:clear"             # the dashboard's round cache and the ODK session token
```

What stays, on purpose:

- `activity_log`. The audit log is append-only, even for the migrator's tools. Its entries for
  the removed rows (corrections, overrides, edits) remain as history.
- `storage/app/exports/`. Old backfill reports; delete them by hand if they would confuse.
- Rounds. Delete those created for the backfill as in step A6, if needed.
