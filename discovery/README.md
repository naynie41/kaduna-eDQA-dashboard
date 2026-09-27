# discovery/ — Phase 0 investigation tools

Throwaway Python scripts for answering the Phase 0 questions about the client's ODK Central
data before any Laravel code is written. Nothing here ships: `discovery/` is excluded from the
Docker build context (`CONVENTION.md` §12.2). The findings feed `ARCHITECTURE.md` §5.5
(field maps) and §11.4 (open questions).

## Rules

These apply to every script in this directory.

1. **Read-only against ODK Central.** The only non-GET call allowed is `POST /v1/sessions` to log in.
   No other POST, and no PATCH, PUT or DELETE. That includes `DELETE /v1/sessions/current`:
   let the token expire rather than log out.
2. **Never print or log the password or the session token.** They must not appear in console
   output, reports, exception messages or saved request dumps.
3. **Throttle.** Sleep `ODK_THROTTLE_SECONDS` (default 1) between paged requests. The production
   server is live and assessors are submitting to it.
4. **Raw data stays local.** Downloads go to `data/` (git-ignored). Only the markdown reports in
   `out/*.md` are committed, so reports must not contain personal data (assessor names, phone
   numbers, GPS). Use counts and question names, not submission values.

## Setup

Requires Python 3.11+.

```bash
cd discovery
python -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install .
cp .env.example .env               # then fill in the credentials
```

| Variable | Meaning |
|---|---|
| `ODK_CENTRAL_URL` | Base URL of the Central server, without `/v1` |
| `ODK_CENTRAL_EMAIL`, `ODK_CENTRAL_PASSWORD` | Web-user login, ideally a read-only (Project Viewer) account |
| `ODK_PROJECT_ID` | Numeric project ID |
| `ODK_FORM_ID` | The DQA form's `xmlFormId` |
| `ODK_THROTTLE_SECONDS` | Delay between paged requests (default `1`) |

## Directories

| Path | Contents | Committed |
|---|---|---|
| `data/` | Downloaded XLSForms, form XML, OData pages | No |
| `out/*.md` | Reports | Yes |
| `out/*.json` | Machine-readable intermediate output | No |

## Tools

None are written yet. Planned, in the order they should run:

| Script | What it does | Answers |
|---|---|---|
| `probe.py` | Logs in, prints the Central version and the project's forms with submission counts | Q-01 (Central vs Aggregate) |
| `form_versions.py` | Lists every form version (`GET /v1/projects/{p}/forms/{f}/versions`) and downloads each version's XLSForm and XML into `data/forms/` | Q-05 (how many versions) |
| `field_diff.py` | Parses each version's XForm XML with lxml and diffs question names and types across versions. Uses rapidfuzz to suggest likely renames. Checks names against the §5.1 contract (`^(avail\|consist\|valid)_m[1-3]_…$`, `select_one` yes/no/na, cascading facility select). Writes `out/field_diff.md` | Q-02, Q-05. The diff is the draft `config/edqa_field_maps.php` |
| `submission_profile.py` | Pages the OData `Submissions` feed (throttled) and reports counts per form version and per submission quarter, plus facility-field shapes (free text vs code). Writes `out/submission_profile.md` | Backfill estimate (§5.6), Q-10 |

Run a script from inside `discovery/` with the venv active, e.g. `python probe.py`.

### `facility_reconcile.py` (written)

Matches every facility seen in the submissions to the national health facility registry and
produces the workbook the client signs off. The signed-off workbook becomes the Phase 1 seed
master list. It reads local files only and makes no ODK calls.

```bash
python facility_reconcile.py --registry data/hfr_export.xlsx --submissions data/submissions/
```

| Option | Meaning |
|---|---|
| `--registry` | Registry export, `.csv` or `.xlsx` (title rows above the header are skipped) |
| `--registry-sheet` | Sheet to read when the workbook has several |
| `--map FIELD=COLUMN` | Registry column for `code`, `name`, `lga`, `ward`, `ownership`, `level`, `state`. Needed only when the headers aren't recognised; the script stops and lists the columns if so |
| `--submissions` | OData JSON pages (`{"value": [...]}`) or ODK CSV/XLSX exports; a file or a directory. Default `data/submissions/` |
| `--sub-map FIELD=PATH` | Question path for `lga`, `ward`, `code`, `name`, `ownership`, `level`, e.g. `name=grp_fac/fac_name`. Otherwise it's found by common question names, per record, so drifting form versions still resolve |
| `--lga-alias VALUE=LGA` | Map an unrecognised LGA value (e.g. an ODK choice code `kd_north`) to one of the 23 |
| `--deadline YYYY-MM-DD` | Client decision deadline. Default: 10 working days from the run |

Only facility fields and the submission date are read; assessor names and other answers are ignored.

**Method.**
1. Names are normalised: case, punctuation, and abbreviations such as PHC/PHCC, HC, HP, MCH,
   Comp., Health Centre/Center and Clinic.
2. Submissions are grouped into distinct facilities by LGA plus registry code. When the code is
   missing or isn't in the registry (e.g. `2019.00`), they are grouped by LGA plus normalised
   name instead.
3. Each facility is matched within its own LGA only: exact code, then exact normalised name,
   then rapidfuzz `token_set_ratio`.
4. Results are sorted by score: 95 or more goes to Matched, 60–94 to Review (with the top 3
   candidates), below 60 to Unmatched.
5. A score of 95+ still goes to Review when two candidates score within 3 points of each other,
   or when the names agree only on facility-type words. `token_set_ratio` scores "PHC" against
   "PHC Kawo" as 100.

**Output.**
- `out/facility_reconciliation.xlsx`, with sheets Read me, Matched, Review, Unmatched,
  Registry never assessed, and Summary. Decision dropdowns (MAP/ADD/REJECT) are on Review and
  Unmatched, and header rows are frozen.
- `out/facility_reconciliation.md`, covering method, counts and the client decisions needed.

### `hosting_check.sh` (written)

Bash script to run **on a candidate server**. It checks that the server can host the Docker
stack (`DEPLOY.md` §2.3, §4, §13) and prints a markdown report to stdout. It needs no Python.

```bash
scp discovery/hosting_check.sh admin@<server>:
ssh admin@<server> 'sudo bash hosting_check.sh --odk-url https://<odk-host> \
    --smtp <smtp-host>:587 --backup-endpoint https://<s3-endpoint> --name <name>' \
    > discovery/out/hosting_<name>.md
```

- **Read-only by default.** It only inspects the host and makes outbound test connections: an
  HTTPS GET to each endpoint, including ODK Central's public `/version.txt`, and an SMTP
  STARTTLS handshake. It never logs in anywhere.
- **What it reports.** PASS/WARN/FAIL for:
  - OS, CPU, RAM and disk against the sizing table;
  - virtualisation (OpenVZ/LXC is a FAIL);
  - kernel ≥ 5.15, cgroups v2 and overlay;
  - Docker Engine and Compose;
  - cPanel/WHM (a FAIL);
  - outbound connectivity;
  - ports 80/443, with all listeners;
  - firewall state.
- **`--with-docker-test`** needs Docker installed. It is the only mode that changes anything,
  and it cleans up afterwards. It:
  - runs `postgres:16-bookworm` with no network and a tmpfs data directory, and tests CHECK,
    jsonb GIN, pg_trgm, and a materialised view with a unique index and REFRESH CONCURRENTLY;
  - runs `debian:bookworm-slim`, installs Chromium and renders a PDF with `--no-sandbox` and a
    256 MB `/dev/shm`;
  - removes the containers and their volumes, and only the images it pulled itself.
- **sudo** is optional, but without it firewall rules and port owners are hidden.
- **Exit status:** 0 when there is no FAIL, 1 on any FAIL, 2 on a usage error.

Record the outcome in [`HOSTING_DECISION.md`](HOSTING_DECISION.md) for client sign-off.
