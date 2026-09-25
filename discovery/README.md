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
