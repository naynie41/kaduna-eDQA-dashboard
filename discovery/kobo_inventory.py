"""KoboToolbox inventory: the asset, its deployed versions, and the submissions per quarter.

The Kobo counterpart of probe.py + form_versions.py (the list) + submission_profile.py (the
counts). Answers, for the second source: how many deployed versions there are, which of them
carry submissions, and the backfill size per quarter (ARCHITECTURE.md §5.6).

A quarter is counted twice: by the visit's own start (the `start` metadata field, what the portal
binds to a round) and by `_submission_time` (when it reached Kobo). A row with no `start` is
counted under "no visit date".

Saves the data rows to data/kobo/submissions/page_<start>.json (git-ignored: raw answers) for
rule_preview.py --source kobo. Writes out/kobo_inventory.md (counts only: no names, no answers)
and out/kobo_form_versions.json (the deployed versions, for kobo_forms.py and field_diff.py).

Usage:
  python kobo_inventory.py                          # against KoboToolbox (discovery/.env)
  python kobo_inventory.py --offline fixtures/kobo  # saved responses, no credentials
  python kobo_inventory.py --max-pages 2            # a quick sample
"""

from __future__ import annotations

import argparse
import json
from collections import Counter
from datetime import datetime

from rich.console import Console

from kobo import DATA_DIR, OUT_DIR, add_source_args, deployed_versions, open_source, run

console = Console()
PAGES_DIR = DATA_DIR / "submissions"


def quarter(value: object) -> str:
    text = str(value or "")
    if len(text) < 7 or not text[:4].isdigit() or not text[5:7].isdigit():
        return "no visit date"
    return f"{text[:4]}-Q{(int(text[5:7]) - 1) // 3 + 1}"


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    add_source_args(p)
    p.add_argument("--max-pages", type=int)
    args = p.parse_args()

    with open_source(args) as kobo:
        asset = kobo.asset()
        versions = kobo.versions()
        deployed = deployed_versions(asset, versions)
        PAGES_DIR.mkdir(parents=True, exist_ok=True)
        for old in PAGES_DIR.glob("page_*.json"):
            old.unlink()
        rows: list[dict] = []
        reported = None
        for start, page in kobo.data_pages(max_pages=args.max_pages):
            (PAGES_DIR / f"page_{start}.json").write_text(json.dumps(page), encoding="utf-8")
            rows += page.get("results", [])
            reported = page.get("count", reported)

    by_visit: Counter[str] = Counter()
    by_received: Counter[str] = Counter()
    by_version: Counter[str] = Counter()
    by_quarter_version: Counter[tuple[str, str]] = Counter()
    edits = 0
    for row in rows:
        visit = quarter(row.get("start"))
        version = str(row.get("__version__") or "(none)")
        by_visit[visit] += 1
        by_received[quarter(row.get("_submission_time"))] += 1
        by_version[version] += 1
        by_quarter_version[(visit, version)] += 1
        if row.get("meta/deprecatedID"):
            edits += 1

    deployed_uids = {str(v.get("uid")) for v in deployed}
    current = str(asset.get("deployed_version_id") or "")
    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "kobo_form_versions.json").write_text(json.dumps({
        "generated": datetime.now().isoformat(timespec="seconds"),
        "asset": asset.get("uid"),
        "versions": [{"version": str(v.get("uid")), "publishedAt": v.get("date_deployed")}
                     for v in deployed],
    }, indent=2), encoding="utf-8")

    lines = [
        "# KoboToolbox inventory",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/kobo_inventory.py`"
        + (f" from saved responses in `{args.offline}`." if args.offline else "."),
        "",
        "## Asset",
        "",
        "| | |",
        "|---|---|",
        f"| Asset | `{asset.get('uid')}`: {asset.get('name', '')} |",
        f"| Type | {asset.get('asset_type', '?')} |",
        f"| Deployed | {'yes' if asset.get('has_deployment') else 'no'}, "
        f"{asset.get('deployment__active', '?')} active; current version `{current or '?'}` |",
        f"| Versions saved | {len(versions)}, of which {len(deployed)} deployed |",
        f"| Submissions | {len(rows):,} downloaded"
        + (f" of {reported:,} reported" if isinstance(reported, int) else "")
        + (" (sample: --max-pages)" if args.max_pages else "") + f"; {edits:,} are edits |",
        "",
        "## Deployed versions",
        "",
        "Each needs an entry under `sources.kobo` in `config/edqa_field_maps.php` (D-63). A version "
        "with submissions and no map quarantines them as UNKNOWN_FORM_VERSION.",
        "",
        "| Version uid | Deployed | Submissions | Current |",
        "|---|---|---|---|",
    ]
    for v in deployed:
        uid = str(v.get("uid"))
        lines.append(f"| `{uid}` | {str(v.get('date_deployed', ''))[:10]} | {by_version.get(uid, 0):,} | "
                     f"{'yes' if uid == current else ''} |")
    stray = sorted(set(by_version) - deployed_uids)
    if stray:
        lines += ["", "Submissions carrying a version that is not a deployed one: "
                  + ", ".join(f"`{u}` ({by_version[u]:,})" for u in stray)
                  + ". Check them before writing the maps."]

    quarters = sorted(set(by_visit) | set(by_received), key=lambda q: (q == "no visit date", q))
    lines += ["", "## Submissions per quarter", "",
              "| Quarter | By visit start | By `_submission_time` |", "|---|---|---|"]
    lines += [f"| {q} | {by_visit.get(q, 0):,} | {by_received.get(q, 0):,} |" for q in quarters]

    lines += ["", "## Per quarter and version (by visit start)", "",
              "| Quarter | Version uid | Submissions |", "|---|---|---|"]
    lines += [f"| {q} | `{v}` | {n:,} |" for (q, v), n in sorted(by_quarter_version.items())]
    (OUT_DIR / "kobo_inventory.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    console.print(f"{len(deployed)} deployed version(s), {len(rows):,} submission(s). "
                  f"Wrote out/kobo_inventory.md, out/kobo_form_versions.json, data/kobo/submissions/")


if __name__ == "__main__":
    run(main)
