"""Download every submission of the DQA form (OData, throttled) and profile the volume and shape.

Answers the backfill-size question (ARCHITECTURE.md §5.6) and gives counts per year, quarter and
form version. The pages it saves are the input for facility_reconcile.py and rule_preview.py.

Pages are fetched with a fixed cut-off ($filter on submissionDate ≤ the first run's start time),
so a resumed download never shifts pages as new submissions arrive.

Writes data/submissions/page_<skip>.json and out/submission_profile.md (counts only: no
names, no answers).

Usage:
  python submission_profile.py              # download (resumes), then profile
  python submission_profile.py --no-download
  python submission_profile.py --max-pages 2   # quick sample
"""

from __future__ import annotations

import argparse
import json
import re
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

from rich.console import Console

from facility_reconcile import SUBMISSION_FIELDS, flatten, resolve_field, resolve_lga
from odk import DATA_DIR, OUT_DIR, OdkClient, load_config, run

console = Console()
PAGES_DIR = DATA_DIR / "submissions"
SNAPSHOT = PAGES_DIR / "_snapshot.json"
TOP = 500


def download(max_pages: int | None) -> None:
    cfg = load_config()
    PAGES_DIR.mkdir(parents=True, exist_ok=True)
    if SNAPSHOT.exists():
        cutoff = json.loads(SNAPSHOT.read_text(encoding="utf-8"))["cutoff"]
    else:
        cutoff = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000Z")
        SNAPSHOT.write_text(json.dumps({"cutoff": cutoff, "form_id": cfg.form_id}),
                            encoding="utf-8")

    existing = sorted(PAGES_DIR.glob("page_*.json"))
    skip = 0
    if existing:
        last = json.loads(existing[-1].read_text(encoding="utf-8"))
        last_skip = int(existing[-1].stem.split("_")[1])
        if len(last.get("value", [])) < TOP:
            console.print(f"Download already complete ({len(existing)} pages, cut-off {cutoff}).")
            return
        skip = last_skip + TOP
        console.print(f"Resuming at record {skip:,} (cut-off {cutoff}).")

    fetched = 0
    with OdkClient(cfg) as odk:
        for page_skip, page in odk.odata_pages(
                top=TOP, skip=skip, filter_=f"__system/submissionDate le {cutoff}"):
            (PAGES_DIR / f"page_{page_skip:07d}.json").write_text(
                json.dumps(page), encoding="utf-8")
            fetched += 1
            total = page.get("@odata.count")
            console.print(f"page at {page_skip:,}: {len(page.get('value', []))} records"
                          + (f" (of {total:,})" if isinstance(total, int) else ""))
            if max_pages and fetched >= max_pages:
                console.print(f"Stopped after {max_pages} page(s) (--max-pages).")
                return


def quarter(date: str) -> str:
    return f"{date[:4]}-Q{(int(date[5:7]) - 1) // 3 + 1}" if re.match(r"\d{4}-\d\d", date) else "?"


def profile() -> None:
    pages = sorted(PAGES_DIR.glob("page_*.json"))
    if not pages:
        raise SystemExit("No pages in data/submissions/; run without --no-download first")
    cutoff = json.loads(SNAPSHOT.read_text(encoding="utf-8"))["cutoff"] if SNAPSHOT.exists() else "?"

    total = 0
    reported_count = None
    by_year: Counter = Counter()
    by_quarter: Counter = Counter()
    by_version: Counter = Counter()
    by_version_year: Counter = Counter()
    visit_year: Counter = Counter()
    review: Counter = Counter()
    edits = deprecated = 0
    att_expected = att_present = 0
    lga_raw: Counter = Counter()
    lga_blank = lga_unresolved = 0
    code_blank = code_numeric = name_no_letters = 0

    for page_file in pages:
        page = json.loads(page_file.read_text(encoding="utf-8"))
        if reported_count is None:
            reported_count = page.get("@odata.count")
        for record in page.get("value", []):
            total += 1
            flat = flatten(record)
            leaves: dict[str, str] = {}
            for path in flat:
                leaves.setdefault(path.rsplit("/", 1)[-1].lower(), path)
            submitted = flat.get("__system/submissionDate", "")
            version = flat.get("__system/formVersion", "") or "(blank)"
            year = submitted[:4] or "?"
            by_year[year] += 1
            by_quarter[quarter(submitted)] += 1
            by_version[version] += 1
            by_version_year[(version, year)] += 1
            review[flat.get("__system/reviewState") or "none"] += 1
            if int(flat.get("__system/edits") or 0) > 0:
                edits += 1
            if flat.get("meta/deprecatedID"):
                deprecated += 1
            att_expected += int(flat.get("__system/attachmentsExpected") or 0)
            att_present += int(flat.get("__system/attachmentsPresent") or 0)
            start = flat.get("start") or flat.get(leaves.get("start", ""), "")
            visit_year[start[:4] if re.match(r"\d{4}", start) else "unparseable"] += 1

            values = {f: resolve_field(flat, leaves, f, {})[1] for f in SUBMISSION_FIELDS}
            lga = values["lga"]
            if not lga:
                lga_blank += 1
            else:
                lga_raw[lga] += 1
                if resolve_lga(lga, {}) is None:
                    lga_unresolved += 1
            code = values["code"]
            if not code:
                code_blank += 1
            elif re.fullmatch(r"[\d.]+", code):
                code_numeric += 1
            if values["name"] and not re.search(r"[A-Za-z]", values["name"]):
                name_no_letters += 1

    years = sorted(by_year)
    versions = sorted(by_version, key=lambda v: -by_version[v])
    lines = [
        "# Submission profile",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/submission_profile.py` from "
        f"{len(pages)} page(s). Cut-off: submissions received up to {cutoff}.",
        "",
        "| | |",
        "|---|---|",
        f"| Submissions downloaded | {total:,} |",
        f"| Reported by ODK (`@odata.count`) | {reported_count if reported_count is not None else '?'} |",
        f"| Form versions in use | {len(by_version)} |",
        f"| Edited in ODK (edits > 0) | {edits:,} |",
        f"| Replacements of an earlier submission (`deprecatedID`) | {deprecated:,} |",
        f"| Attachments expected / present | {att_expected:,} / {att_present:,} |",
        "",
        "Backfill estimate (§5.6): "
        f"{total:,} submissions ÷ 500 per page = {-(-total // 500):,} pages.",
        "",
        "## Per year (by date received in ODK)",
        "",
        "| Year | Submissions |",
        "|---|---|",
    ]
    lines += [f"| {y} | {by_year[y]:,} |" for y in years]
    lines += ["", "## Per quarter received", "", "| Quarter | Submissions |", "|---|---|"]
    lines += [f"| {q} | {by_quarter[q]:,} |" for q in sorted(by_quarter)]
    lines += ["", "## Per year of visit (`start` field)", "", "| Year | Submissions |", "|---|---|"]
    lines += [f"| {y} | {visit_year[y]:,} |" for y in sorted(visit_year)]
    lines += ["", "## Form version by year received", "",
              "| Version | " + " | ".join(years) + " | Total |",
              "|---|" + "---|" * (len(years) + 1)]
    for v in versions:
        lines.append(f"| `{v}` | " + " | ".join(f"{by_version_year[(v, y)]:,}" for y in years)
                     + f" | {by_version[v]:,} |")
    lines += [
        "",
        "## Review state in ODK",
        "",
        "| State | Submissions |",
        "|---|---|",
        *[f"| {k} | {n:,} |" for k, n in review.most_common()],
        "",
        "## Facility fields (shape only)",
        "",
        "| | Submissions |",
        "|---|---|",
        f"| LGA blank | {lga_blank:,} |",
        f"| LGA not recognisable as one of the 23 (by spelling) | {lga_unresolved:,} |",
        f"| Distinct LGA values seen | {len(lga_raw):,} |",
        f"| Facility code blank | {code_blank:,} |",
        f"| Facility code purely numeric (e.g. `2019.00`) | {code_numeric:,} |",
        f"| Facility name with no letters | {name_no_letters:,} |",
        "",
        "Fields were found by common question names per record; `field_diff.py` gives the exact "
        "paths per form version.",
    ]
    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "submission_profile.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    console.print(f"{total:,} submissions profiled. Wrote out/submission_profile.md")


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    p.add_argument("--no-download", action="store_true", help="profile pages already on disk")
    p.add_argument("--max-pages", type=int, help="stop after this many new pages")
    args = p.parse_args()
    if not args.no_download:
        download(args.max_pages)
    profile()


if __name__ == "__main__":
    run(main)
