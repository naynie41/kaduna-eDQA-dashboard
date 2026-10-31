"""KoboToolbox inventory for credentials day: who the token is, the asset, its deployed versions,
the submissions per quarter, what the account may do, and the REST Services. Read-only.

  a. The token: the account it authenticates as (username only; nothing else from /me/ is kept).
  b. No asset chosen (KOBO_ASSET_UID empty, no --asset): lists the survey assets the token can see
     (uid, name, deployed or not, submissions, last modified) and stops, so one can be picked.
  c. The asset: name, owner, deployment status, the deployed versions with their dates, the
     submission count, and the submissions per quarter by `_submission_time` (only that field
     is fetched: no answer is read).
  d. Permissions: what this account may do on the asset (`effective_permissions`). "VIEW-ONLY:
     safe" when it holds only view permissions; otherwise a WARNING naming what else it can do.
     Also how many other assets the account owns (a count).
  e. REST Services (hooks) on the asset: reported only. A view-only account may not see them.
  f. The server's region, from the base URL, for Q-17.

Writes out/kobo_inventory.md and out/kobo_inventory.json (no token, no submission content), and
out/kobo_form_versions.json for kobo_forms.py and field_diff.py. With --download it also saves
the full data rows to data/kobo/submissions/ (git-ignored, raw answers) for
rule_preview.py --source kobo.

Usage:
  python kobo_inventory.py                          # against KoboToolbox (discovery/.env)
  python kobo_inventory.py --asset aBcD…            # another asset than KOBO_ASSET_UID
  python kobo_inventory.py --download               # also save the rows, for rule_preview.py
  python kobo_inventory.py --offline fixtures/kobo  # saved responses, no credentials
"""

from __future__ import annotations

import argparse
import json
from collections import Counter
from datetime import datetime

from rich.console import Console
from rich.table import Table

from kobo import (DATA_DIR, OUT_DIR, VIEW_ONLY, KoboForbidden, add_source_args, deployed_versions,
                  open_source, region, run)

console = Console()
PAGES_DIR = DATA_DIR / "submissions"


def quarter(value: object) -> str:
    text = str(value or "")
    if len(text) < 7 or not text[:4].isdigit() or not text[5:7].isdigit():
        return "no date"
    return f"{text[:4]}-Q{(int(text[5:7]) - 1) // 3 + 1}"


def permission_verdict(codenames: list[str], is_owner: bool) -> tuple[str, list[str]]:
    beyond = sorted(set(codenames) - VIEW_ONLY)
    if is_owner or beyond:
        return "WARNING: this token can edit/delete forms or data", beyond
    if not codenames:
        return "UNKNOWN: Kobo returned no permissions for this account on the asset", []
    return "VIEW-ONLY: safe", []


def list_assets(kobo, username: str, base_url: str) -> None:
    surveys = [a for a in kobo.assets() if a.get("asset_type") == "survey"]
    table = Table(title=f"Survey assets visible to {username}")
    for column in ("uid", "name", "deployed", "submissions", "last modified"):
        table.add_column(column)
    rows = []
    for a in sorted(surveys, key=lambda a: str(a.get("date_modified", "")), reverse=True):
        row = {"uid": a.get("uid"), "name": a.get("name"),
               "deployed": a.get("deployment_status") or ("deployed" if a.get("has_deployment") else "draft"),
               "submissions": a.get("deployment__submission_count"),
               "last_modified": str(a.get("date_modified", ""))[:10]}
        rows.append(row)
        table.add_row(str(row["uid"]), str(row["name"]), str(row["deployed"]), str(row["submissions"] or 0),
                      row["last_modified"])
    console.print(table)
    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "kobo_inventory.json").write_text(json.dumps({
        "generated": datetime.now().isoformat(timespec="seconds"), "account": username,
        "server": base_url, "region": region(base_url), "asset": None, "survey_assets": rows}, indent=2),
        encoding="utf-8")
    console.print(f"\n{len(rows)} survey asset(s). No asset chosen: set KOBO_ASSET_UID in "
                  f"discovery/.env (or pass --asset UID) and run again. Wrote out/kobo_inventory.json")


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    add_source_args(p)
    p.add_argument("--asset", help="the asset uid (default KOBO_ASSET_UID)")
    p.add_argument("--max-pages", type=int, help="a sample: at most this many pages of 1000")
    p.add_argument("--download", action="store_true",
                   help="also save the full data rows to data/kobo/submissions/ (for rule_preview.py)")
    args = p.parse_args()

    with open_source(args, require_asset=False) as kobo:
        username = str(kobo.me().get("username", "?"))   # a. who the token is
        console.print(f"Token accepted: authenticates as [bold]{username}[/bold]")
        base_url = kobo.base_url

        if not kobo.asset_uid:                            # b. no asset: list them and stop
            list_assets(kobo, username, base_url)
            return

        asset = kobo.asset()                              # c. the asset
        deployed = deployed_versions(kobo.versions())
        by_quarter: Counter[str] = Counter()
        fetched = 0
        if args.download:
            PAGES_DIR.mkdir(parents=True, exist_ok=True)
            for old in PAGES_DIR.glob("page_*.json"):
                old.unlink()
        pages = kobo.data_pages(fields=None if args.download else ["_submission_time"],
                                max_pages=args.max_pages)
        for start, page in pages:
            if args.download:
                (PAGES_DIR / f"page_{start}.json").write_text(json.dumps(page), encoding="utf-8")
            for row in page.get("results", []):
                by_quarter[quarter(row.get("_submission_time"))] += 1
                fetched += 1

        all_assets = kobo.assets()                        # d. permissions, and what else it owns
        codenames = sorted({str(p.get("codename")) for p in asset.get("effective_permissions") or []})
        is_owner = asset.get("owner__username") == username
        verdict, beyond = permission_verdict(codenames, is_owner)
        others_owned = sum(1 for a in all_assets if a.get("owner__username") == username and a.get("uid") != kobo.asset_uid)

        try:                                              # e. REST Services (report only)
            hooks = [{"name": h.get("name"), "active": h.get("active"), "export_type": h.get("export_type"),
                      "auth_level": h.get("auth_level")} for h in kobo.hooks()]
            hooks_note = f"{len(hooks)} configured" if hooks else "none configured"
        except KoboForbidden:
            hooks = None
            hooks_note = "not visible to this account (HTTP 403); check in the Kobo project's Settings → REST Services"

    reported = asset.get("deployment__submission_count")
    report = {
        "generated": datetime.now().isoformat(timespec="seconds"),
        "source": "offline fixtures" if args.offline else "live",
        "server": base_url,
        "region": region(base_url),
        "account": username,
        "asset": {
            "uid": asset.get("uid"), "name": asset.get("name"), "owner": asset.get("owner__username"),
            "deployment_status": asset.get("deployment_status"), "deployment_active": asset.get("deployment__active"),
            "deployed_version_id": asset.get("deployed_version_id"),
            "deployed_versions": [{"uid": v.get("uid"), "date_deployed": v.get("date_deployed")} for v in deployed],
            "submission_count": reported,
            "submissions_fetched": fetched,
            "submissions_per_quarter": dict(sorted(by_quarter.items())),
        },
        "permissions": {"account_permissions": codenames, "account_is_owner": is_owner, "verdict": verdict,
                        "beyond_view": beyond, "other_assets_owned": others_owned},
        "rest_services": hooks,
        "rest_services_note": hooks_note,
    }
    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "kobo_inventory.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    (OUT_DIR / "kobo_form_versions.json").write_text(json.dumps({
        "generated": report["generated"], "asset": asset.get("uid"),
        "versions": [{"version": v.get("uid"), "publishedAt": v.get("date_deployed")} for v in deployed],
    }, indent=2), encoding="utf-8")

    current = asset.get("deployed_version_id")
    lines = [
        "# KoboToolbox inventory",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/kobo_inventory.py`"
        + (f" from saved responses in `{args.offline}`." if args.offline else " against the live server."),
        "",
        "## Server and account",
        "",
        "| | |",
        "|---|---|",
        f"| Server | `{base_url}` |",
        f"| Region (Q-17) | {region(base_url)} |",
        f"| Token authenticates as | `{username}` |",
        "",
        "## Permissions on the asset",
        "",
        f"**{verdict}**",
        "",
        f"- This account's permissions: {', '.join(f'`{c}`' for c in codenames) or 'none returned'}.",
        f"- The account {'**owns** the asset' if is_owner else 'does not own the asset'}.",
    ]
    if beyond:
        lines.append(f"- Beyond viewing it can: {', '.join(f'`{c}`' for c in beyond)}. Use a dedicated "
                     "account with only view form and view submissions on this asset (SECURITY.md §6, Q-18).")
    lines += [f"- Other assets this account owns: {others_owned}.", "",
              "## Asset", "", "| | |", "|---|---|",
              f"| Asset | `{asset.get('uid')}`: {asset.get('name', '')} |",
              f"| Owner | `{asset.get('owner__username', '?')}` |",
              f"| Deployment | {asset.get('deployment_status', '?')}; active: {asset.get('deployment__active')} |",
              f"| Current deployed version | `{current or '?'}` |",
              f"| Submissions | {reported if reported is not None else '?'} reported by Kobo; {fetched:,} counted"
              + (" (sample: --max-pages)" if args.max_pages else "") + " |",
              "", "## Deployed versions", "",
              "Each needs an entry under `sources.kobo` in `config/edqa_field_maps.php` (D-63).", "",
              "| Version uid | Deployed | Current |", "|---|---|---|"]
    lines += [f"| `{v.get('uid')}` | {str(v.get('date_deployed', ''))[:10]} | {'yes' if v.get('uid') == current else ''} |"
              for v in deployed]
    lines += ["", "## Submissions per quarter (by `_submission_time`, UTC)", "", "| Quarter | Submissions |",
              "|---|---|"]
    lines += [f"| {q} | {n:,} |" for q, n in sorted(by_quarter.items())]
    lines += ["", "## REST Services", "", f"{hooks_note[0].upper()}{hooks_note[1:]}."]
    if hooks:
        lines += ["", "| Name | Active | Export type | Auth |", "|---|---|---|---|"]
        lines += [f"| {h['name']} | {h['active']} | {h['export_type']} | {h['auth_level']} |" for h in hooks]
    (OUT_DIR / "kobo_inventory.md").write_text("\n".join(lines) + "\n", encoding="utf-8")

    style = "bold green" if verdict.startswith("VIEW-ONLY") else "bold red"
    console.print(f"[{style}]{verdict}[/{style}]")
    console.print(f"{len(deployed)} deployed version(s), {fetched:,} submission(s) counted. "
                  f"Wrote out/kobo_inventory.md, out/kobo_inventory.json, out/kobo_form_versions.json")


if __name__ == "__main__":
    run(main)
