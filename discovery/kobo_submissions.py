"""Download every KoboToolbox submission of the asset, paged, throttled and resumable. Read-only.

Pages of up to 1000 rows (the data endpoint's maximum), sorted by `_id`, with a fixed cut-off: the
first run records the time it started, and every page asks only for `_submission_time` up to it
(`query={"_submission_time": {"$lte": …}}`), so a resumed download never shifts its pages as new
submissions arrive. A checkpoint file says which pages are done; run it again to carry on, or
pass --restart to start over.

Writes data/submissions/kobo/page_<start>.json (git-ignored: raw answers) and
data/submissions/kobo/_checkpoint.json. The token never goes in either.

Usage:
  python kobo_submissions.py              # download, or carry on where the last run stopped
  python kobo_submissions.py --restart    # a new cut-off, every page again
  python kobo_submissions.py --offline fixtures/kobo
"""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone

from rich.console import Console

from kobo import HERE, PAGE_SIZE, KoboClient, add_source_args, open_source, run

console = Console()
PAGES_DIR = HERE / "data" / "submissions" / "kobo"
CHECKPOINT = PAGES_DIR / "_checkpoint.json"


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    add_source_args(p)
    p.add_argument("--restart", action="store_true", help="forget the checkpoint and start again")
    args = p.parse_args()
    PAGES_DIR.mkdir(parents=True, exist_ok=True)

    with open_source(args) as kobo:
        state = json.loads(CHECKPOINT.read_text(encoding="utf-8")) if CHECKPOINT.is_file() and not args.restart else None
        if state is not None and state.get("asset") != kobo.asset_uid:
            console.print(f"The checkpoint is for asset {state.get('asset')}, not {kobo.asset_uid}: starting again")
            state = None
        if state is None:
            for old in PAGES_DIR.glob("page_*.json"):
                old.unlink()
            # Kobo's _submission_time is UTC with no zone, to the second.
            state = {"asset": kobo.asset_uid, "cutoff": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S"),
                     "next_start": 0, "pages": 0, "rows": 0, "done": False}
        if state["done"]:
            console.print(f"Already complete: {state['rows']} submission(s) in {state['pages']} page(s), "
                          f"up to {state['cutoff']} UTC. --restart to download again.")
            return

        while True:
            start = state["next_start"]
            if isinstance(kobo, KoboClient):
                page = kobo.get_json(f"{kobo.asset_path}/data/", params={
                    "start": start, "limit": PAGE_SIZE, "sort": json.dumps({"_id": 1}),
                    "query": json.dumps({"_submission_time": {"$lte": state["cutoff"]}}),
                })
            else:  # the fixtures hold their pages as saved
                page = next((pg for s, pg in kobo.data_pages() if s == start), {"results": [], "next": None})
            rows = page.get("results", [])
            (PAGES_DIR / f"page_{start}.json").write_text(json.dumps(page), encoding="utf-8")
            state.update(next_start=start + len(rows), pages=state["pages"] + 1, rows=state["rows"] + len(rows),
                         count=page.get("count"))
            done = not page.get("next") or len(rows) < PAGE_SIZE
            state["done"] = done
            CHECKPOINT.write_text(json.dumps(state, indent=2), encoding="utf-8")
            console.print(f"page at {start}: {len(rows)} row(s); {state['rows']} of {page.get('count', '?')}")
            if done or not rows:
                break

    console.print(f"{state['rows']} submission(s) in {state['pages']} page(s), submitted up to {state['cutoff']} UTC. "
                  f"Wrote data/submissions/kobo/")


if __name__ == "__main__":
    run(main)
