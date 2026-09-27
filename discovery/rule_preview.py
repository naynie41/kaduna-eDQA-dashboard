"""Defect preview: how many historical submissions the portal's validation rules would quarantine
or flag, by rule and year. Evidence for the rebuild, and a backfill-effort signal.

A preview, not the real pipeline (ARCHITECTURE.md §6). It runs every rule it can evaluate offline
and collects every failure per submission, as the pipeline does. A rule whose inputs are missing
is reported as "not evaluated", never guessed:
- FACILITY_UNKNOWN / FACILITY_LGA_MISMATCH need --registry (the national registry stands in for
  the master list, which is not signed off yet).
- ROUND_WINDOW, DUPLICATE_ASSESSMENT and SCORE_JUMP need round windows: --rounds (Q-10), or
  --calendar-rounds to use calendar quarters as a labelled proxy.
- ITEMS_INCOMPLETE treats every scored question in the version's field map as required; the real
  rule will use the confirmed map.

Reads data/submissions/ (submission_profile.py) and out/field_map.draft.json (field_diff.py).
Local files only; no ODK calls.

Writes out/rule_preview.md (counts only) and out/rule_preview.json (per-instance codes; not
committed).

Usage:
  python rule_preview.py --registry data/hfr.xlsx --calendar-rounds
  python rule_preview.py --registry data/hfr.xlsx --rounds data/rounds.csv   # year,quarter,window_start,window_end
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta
from pathlib import Path

from rich.console import Console
from rich.table import Table

from facility_reconcile import (REGISTRY_FIELDS, compact, flatten, load_registry,
                                normalise_code, parse_map, resolve_lga)

HERE = Path(__file__).resolve().parent
OUT_DIR = HERE / "out"
console = Console()

# ARCHITECTURE.md §6 catalogue, in order. Soft thresholds from config/edqa.php (defaults).
RULES = [
    ("UNKNOWN_FORM_VERSION", "Hard"), ("COLUMN_DRIFT", "Hard"), ("LGA_UNKNOWN", "Hard"),
    ("FACILITY_UNKNOWN", "Hard"), ("FACILITY_LGA_MISMATCH", "Hard"), ("ITEMS_INCOMPLETE", "Hard"),
    ("SCORE_RANGE", "Hard"), ("END_BEFORE_START", "Hard"), ("ROUND_WINDOW", "Hard"),
    ("DUPLICATE_ASSESSMENT", "Hard"), ("SCORE_JUMP", "Soft"), ("ALL_PERFECT", "Soft"),
    ("VISIT_TOO_SHORT", "Soft"), ("ASSESSOR_VOLUME", "Soft"),
]
HARD = {c for c, s in RULES if s == "Hard"}
SCORE_JUMP_POINTS = 30
VISIT_MIN_MINUTES = 20
ASSESSOR_MAX_PER_DAY = 6

RESERVED_WARDS = {"quarterly", "null", "none", "january", "february", "march", "april", "may",
                  "june", "july", "august", "september", "october", "november", "december"}
DIMS = ("availability", "consistency", "validity")
PREFIX = {"avail": "availability", "consist": "consistency", "valid": "validity"}


@dataclass
class Round:
    year: int
    quarter: int
    start: date
    end: date

    @property
    def key(self) -> str:
        return f"{self.year}-Q{self.quarter}"


@dataclass
class Sub:
    instance_id: str
    year: str
    version: str
    flat: dict[str, str]
    failures: list[tuple[str, str]] = field(default_factory=list)
    started: datetime | None = None
    ended: datetime | None = None
    lga: str | None = None
    code: str = ""
    scores: dict[tuple[str, int], float | None] = field(default_factory=dict)
    overall: float | None = None
    round: Round | None = None
    superseded: bool = False

    def fail(self, code: str, detail: str) -> None:
        self.failures.append((code, detail))

    @property
    def hard(self) -> bool:
        return any(c in HARD for c, _ in self.failures)


def parse_dt(raw: str) -> datetime | None:
    if not raw:
        return None
    try:
        return datetime.fromisoformat(raw.strip().replace("Z", "+00:00"))
    except ValueError:
        return None


def load_rounds(path: Path) -> list[Round]:
    rounds = []
    with path.open(encoding="utf-8-sig", newline="") as f:
        for row in csv.DictReader(f):
            rounds.append(Round(int(row["year"]), int(row["quarter"]),
                                date.fromisoformat(row["window_start"]),
                                date.fromisoformat(row["window_end"])))
    return rounds


def calendar_rounds(years: set[int]) -> list[Round]:
    rounds = []
    for y in sorted(years):
        for q in range(1, 5):
            start = date(y, 3 * q - 2, 1)
            end = (date(y + (q == 4), (3 * q) % 12 + 1, 1)) - timedelta(days=1)
            rounds.append(Round(y, q, start, end))
    return rounds


def scored_items(entry: dict, flat: dict[str, str]) -> dict[str, tuple[str, int]]:
    """path -> (dimension, slot) for this submission's version."""
    items: dict[str, tuple[str, int]] = {p: (d, s) for p, (d, s, _) in
                                         (entry.get("scored_items") or {}).items()}
    pattern = entry.get("scored_prefix_pattern")
    if pattern:
        rx = re.compile(pattern.strip("/"))
        # The form's own list when field_diff.py provided it, so unanswered items still count.
        for path in entry.get("scored_paths") or list(flat):
            m = rx.match(path.rsplit("/", 1)[-1])
            if m:
                items[path] = (PREFIX[m.group(1)], int(m.group(2)))
    return items


def evaluate(sub: Sub, entry: dict | None, registry: dict[str, str] | None,
             rounds: list[Round] | None, lga_aliases: dict[str, str]) -> None:
    if entry is None:
        sub.fail("UNKNOWN_FORM_VERSION", f"version '{sub.version}' has no field map")
        return  # every other rule depends on the parse
    flat = sub.flat

    def get(key: str) -> str:
        path = entry.get(key)
        return flat.get(path, "") if path else ""

    started_raw, ended_raw, ward = get("started_at"), get("ended_at"), get("ward_code")
    sub.started, sub.ended = parse_dt(started_raw), parse_dt(ended_raw)
    drift = []
    if started_raw and sub.started is None:
        drift.append(f"start '{started_raw[:20]}'")
    if ended_raw and sub.ended is None:
        drift.append(f"end '{ended_raw[:20]}'")
    if ward and (ward.strip().lower() in RESERVED_WARDS or re.fullmatch(r"[\d.\s]+", ward)):
        drift.append(f"ward '{ward[:20]}'")
    if drift:
        sub.fail("COLUMN_DRIFT", ", ".join(drift))

    lga_raw = get("lga_code")
    sub.lga = resolve_lga(lga_raw, lga_aliases)
    if sub.lga is None:
        sub.fail("LGA_UNKNOWN", "lga was blank" if not lga_raw else f"lga '{lga_raw[:30]}'")

    sub.code = normalise_code(get("facility_code"))
    if registry is not None:
        if sub.code not in registry:
            sub.fail("FACILITY_UNKNOWN", "facility code blank" if not sub.code
                     else f"facility '{sub.code[:30]}' not in registry")
        elif sub.lga and registry[sub.code] != sub.lga:
            sub.fail("FACILITY_LGA_MISMATCH", f"registry LGA {registry[sub.code]}, submitted {sub.lga}")

    choices = entry.get("choices")
    items = scored_items(entry, flat)
    cells = {(d, s) for d, s in items.values()}
    missing = [p for p in items if not flat.get(p)]
    unmappable = [p for p in items if flat.get(p) and (choices is None or flat[p] not in choices)]
    if len(cells) < 9 or missing or unmappable:
        parts = []
        if len(cells) < 9:
            parts.append(f"{9 - len(cells)} of 9 dimension-month cells have no scored question")
        if missing:
            parts.append(f"{len(missing)} items unanswered")
        if unmappable:
            parts.append(f"{len(unmappable)} items with values outside the choice map")
        sub.fail("ITEMS_INCOMPLETE", "; ".join(parts))
    else:
        tallies: dict[tuple[str, int], list[int]] = defaultdict(lambda: [0, 0])
        for path, cell in items.items():
            outcome = choices[flat[path]]
            if outcome == "pass":
                tallies[cell][0] += 1
            if outcome in ("pass", "fail"):
                tallies[cell][1] += 1
        for d in DIMS:
            for s in (1, 2, 3):
                passed, applicable = tallies[(d, s)]
                sub.scores[(d, s)] = passed / applicable * 100 if applicable else None
        out_of_range = [f"{d}_m{s} {v:.2f}" for (d, s), v in sub.scores.items()
                        if v is not None and not 0 <= v <= 100]
        if out_of_range:
            sub.fail("SCORE_RANGE", ", ".join(out_of_range))
        dim_scores = []
        for d in DIMS:
            months = [v for (dd, _), v in sub.scores.items() if dd == d and v is not None]
            if months:
                dim_scores.append(sum(months) / len(months))
        sub.overall = sum(dim_scores) / len(dim_scores) if len(dim_scores) == 3 else None

    if sub.started and sub.ended and sub.ended < sub.started:
        sub.fail("END_BEFORE_START", f"end {sub.ended:%Y-%m-%d %H:%M} before start")

    if rounds is not None:
        visit = sub.started.date() if sub.started else None
        sub.round = next((r for r in rounds if visit and r.start <= visit <= r.end), None)
        if sub.round is None:
            sub.fail("ROUND_WINDOW", "no visit date" if visit is None
                     else f"visit {visit} outside every round window")


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    p.add_argument("--submissions", type=Path, default=HERE / "data" / "submissions")
    p.add_argument("--field-map", type=Path, default=OUT_DIR / "field_map.draft.json")
    p.add_argument("--registry", type=Path, help="registry export for the facility rules")
    p.add_argument("--registry-sheet")
    p.add_argument("--map", action="append", default=[], metavar="FIELD=COLUMN")
    p.add_argument("--lga-alias", action="append", default=[], metavar="VALUE=LGA")
    rounds_group = p.add_mutually_exclusive_group()
    rounds_group.add_argument("--rounds", type=Path,
                              help="CSV: year,quarter,window_start,window_end")
    rounds_group.add_argument("--calendar-rounds", action="store_true",
                              help="proxy: treat each calendar quarter as a round")
    args = p.parse_args()

    if not args.field_map.exists():
        sys.exit(f"{args.field_map} not found; run field_diff.py first")
    maps = json.loads(args.field_map.read_text(encoding="utf-8"))["versions"]
    pages = sorted(args.submissions.glob("page_*.json"))
    if not pages:
        sys.exit(f"No pages in {args.submissions}; run submission_profile.py first")

    aliases: dict[str, str] = {}
    for pair in args.lga_alias:
        value, _, lga = pair.partition("=")
        canonical = resolve_lga(lga, {})
        if not value or canonical is None:
            sys.exit(f"--lga-alias {pair!r}: expected VALUE=LGA with LGA one of the 23")
        aliases[compact(value)] = canonical

    registry: dict[str, str] | None = None
    if args.registry:
        reg = load_registry(args.registry, args.registry_sheet,
                            parse_map(args.map, set(REGISTRY_FIELDS), "--map"), aliases)
        if "code" not in reg.columns:
            sys.exit("The registry has no code column; pass --map code=... or omit --registry")
        registry = {f.code: f.lga for f in reg.facilities if f.code}

    subs: list[Sub] = []
    for page_file in pages:
        for record in json.loads(page_file.read_text(encoding="utf-8")).get("value", []):
            flat = flatten(record)
            subs.append(Sub(
                instance_id=flat.get("meta/instanceID") or flat.get("__id", ""),
                year=(flat.get("__system/submissionDate") or "?")[:4],
                version=flat.get("__system/formVersion", ""),
                flat=flat))

    # An edit replaces the submission named in its deprecatedID; the replaced one is not assessed.
    replaced = {s.flat["meta/deprecatedID"] for s in subs if s.flat.get("meta/deprecatedID")}
    for s in subs:
        s.superseded = s.instance_id in replaced
    live = [s for s in subs if not s.superseded]

    rounds: list[Round] | None = None
    rounds_label = "not evaluated (no --rounds; Q-10)"
    if args.rounds:
        rounds = load_rounds(args.rounds)
        rounds_label = f"{len(rounds)} rounds from `{args.rounds.name}`"
    elif args.calendar_rounds:
        years = {int(s.year) for s in live if s.year.isdigit()}
        years |= {y + d for y in years for d in (-1, 1)}
        rounds = calendar_rounds(years)
        rounds_label = "calendar quarters used as a **proxy** for round windows"

    for s in live:
        evaluate(s, maps.get(s.version), registry, rounds, aliases)

    # DUPLICATE_ASSESSMENT: in received order, the first otherwise-accepted submission per
    # (round, facility) is assessed; later ones fail.
    if rounds is not None:
        seen: set[tuple[str, str]] = set()
        for s in sorted(live, key=lambda s: s.flat.get("__system/submissionDate", "")):
            if s.hard or s.round is None or not s.code:
                continue
            key = (s.round.key, s.code)
            if key in seen:
                s.fail("DUPLICATE_ASSESSMENT", f"{s.round.key} already assessed for this facility")
            seen.add(key)

    accepted = [s for s in live if not s.hard]
    for s in accepted:
        if s.scores and all(v == 100 for v in s.scores.values()):
            s.fail("ALL_PERFECT", "all nine scores are 100")
        if s.started and s.ended and s.ended >= s.started:
            minutes = (s.ended - s.started).total_seconds() / 60
            if minutes < VISIT_MIN_MINUTES:
                s.fail("VISIT_TOO_SHORT", f"visit took {minutes:.0f} min")

    assessor_path_by_version = {v: e.get("assessor") for v, e in maps.items()}
    per_day: dict[tuple[str, date], set[str]] = defaultdict(set)
    for s in accepted:
        path = assessor_path_by_version.get(s.version)
        who = s.flat.get(path, "").strip().lower() if path else ""
        if who and s.started:
            per_day[(who, s.started.date())].add(s.code or s.instance_id)
    busy = {k for k, v in per_day.items() if len(v) > ASSESSOR_MAX_PER_DAY}
    assessor_evaluated = bool(per_day)
    for s in accepted:
        path = assessor_path_by_version.get(s.version)
        who = s.flat.get(path, "").strip().lower() if path else ""
        if s.started and (who, s.started.date()) in busy:
            s.fail("ASSESSOR_VOLUME", f"assessor had more than {ASSESSOR_MAX_PER_DAY} facilities that day")

    jump_evaluated = rounds is not None
    if jump_evaluated:
        history: dict[str, list[Sub]] = defaultdict(list)
        for s in accepted:
            if s.round and s.code and s.overall is not None and not any(
                    c == "DUPLICATE_ASSESSMENT" for c, _ in s.failures):
                history[s.code].append(s)
        for runs in history.values():
            runs.sort(key=lambda s: (s.round.year, s.round.quarter))
            for prev, cur in zip(runs, runs[1:]):
                if abs(cur.overall - prev.overall) > SCORE_JUMP_POINTS:
                    cur.fail("SCORE_JUMP", f"overall moved {cur.overall - prev.overall:+.1f} points")

    # ---------------------------------------------------------------- evidence from the old data
    typed_scores: Counter = Counter()
    typed_max = 0.0
    for s in live:
        mapped = set((maps.get(s.version) or {}).get("scored_items") or {})
        for path, value in s.flat.items():
            leaf = path.rsplit("/", 1)[-1].lower()
            named = "score" in leaf or "percent" in leaf or leaf.endswith("pct")
            if (named or path in mapped) and value:
                try:
                    number = float(value)
                except ValueError:
                    continue
                if not 0 <= number <= 100:
                    typed_scores[s.year] += 1
                    typed_max = max(typed_max, number)
                    break

    not_evaluated = {
        "FACILITY_UNKNOWN": None if registry is not None else "needs --registry (master list pending, Q-06)",
        "FACILITY_LGA_MISMATCH": None if registry is not None else "needs --registry",
        "ROUND_WINDOW": None if rounds is not None else "needs round windows (Q-10)",
        "DUPLICATE_ASSESSMENT": None if rounds is not None else "needs round windows (Q-10)",
        "SCORE_JUMP": None if jump_evaluated else "needs round windows (Q-10)",
        "ASSESSOR_VOLUME": None if assessor_evaluated else "no assessor field in the field map",
    }

    years = sorted({s.year for s in live})
    counts: dict[str, Counter] = {c: Counter() for c, _ in RULES}
    for s in live:
        for code in {c for c, _ in s.failures}:
            counts[code][s.year] += 1
    total_by_year = Counter(s.year for s in live)
    quarantined = Counter(s.year for s in live if s.hard)
    flagged = Counter(s.year for s in live if not s.hard and s.failures)

    def pct(n: int, d: int) -> str:
        return f"{n:,} ({n / d:.0%})" if d else "0"

    lines = [
        "# Defect preview: validation rules on historical submissions",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/rule_preview.py` from "
        f"{len(subs):,} submissions ({len(subs) - len(live):,} replaced by later edits and "
        "excluded).",
        "",
        "| Input | Used |",
        "|---|---|",
        f"| Field map | `{args.field_map.name}` (draft; CONFIRM items not yet checked) |",
        f"| Facility list | {'national registry `' + args.registry.name + '` as a proxy for the master list' if registry is not None else 'none'} |",
        f"| Round windows | {rounds_label} |",
        "",
        "## Outcome per year",
        "",
        "| Year | Submissions | Would be quarantined (any hard rule) | Accepted with a flag | Accepted clean |",
        "|---|---|---|---|---|",
    ]
    for y in years:
        clean = total_by_year[y] - quarantined[y] - flagged[y]
        lines.append(f"| {y} | {total_by_year[y]:,} | {pct(quarantined[y], total_by_year[y])} | "
                     f"{pct(flagged[y], total_by_year[y])} | {pct(clean, total_by_year[y])} |")
    t = len(live)
    lines.append(f"| **All** | **{t:,}** | **{pct(sum(quarantined.values()), t)}** | "
                 f"{pct(sum(flagged.values()), t)} | "
                 f"{pct(t - sum(quarantined.values()) - sum(flagged.values()), t)} |")
    lines += ["", "## Failures by rule and year", "",
              "A submission can fail several rules; each is counted once per rule.", "",
              "| # | Rule | Severity | " + " | ".join(years) + " | Total |",
              "|---|---|---|" + "---|" * (len(years) + 1)]
    for i, (code, severity) in enumerate(RULES, start=1):
        if not_evaluated.get(code):
            lines.append(f"| {i} | `{code}` | {severity} | "
                         + " | ".join("—" for _ in years) + f" | not evaluated: {not_evaluated[code]} |")
        else:
            lines.append(f"| {i} | `{code}` | {severity} | "
                         + " | ".join(f"{counts[code][y]:,}" for y in years)
                         + f" | {sum(counts[code].values()):,} |")
    lines += [
        "",
        "Rules 7 (SCORE_RANGE) can only fail on corrupt data: the portal computes scores from "
        "yes/no/na answers, so they cannot leave 0–100.",
        "",
        "## Evidence from the old data",
        "",
        "| | " + " | ".join(years) + " | Total |",
        "|---|" + "---|" * (len(years) + 1),
        "| Submissions carrying a typed score outside 0–100 | "
        + " | ".join(f"{typed_scores[y]:,}" for y in years) + f" | {sum(typed_scores.values()):,} |",
        "",
        f"Largest typed score seen: {typed_max:,.2f}." if typed_scores else
        "No typed score field outside 0–100 was found.",
    ]
    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "rule_preview.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    (OUT_DIR / "rule_preview.json").write_text(json.dumps(
        [{"instance_id": s.instance_id, "year": s.year, "version": s.version,
          "failures": s.failures} for s in live if s.failures], indent=1), encoding="utf-8")

    table = Table(title="Defect preview")
    table.add_column("Year")
    table.add_column("Submissions", justify="right")
    table.add_column("Quarantined", justify="right")
    table.add_column("Flagged", justify="right")
    for y in years:
        table.add_row(y, f"{total_by_year[y]:,}", pct(quarantined[y], total_by_year[y]),
                      pct(flagged[y], total_by_year[y]))
    console.print(table)
    console.print("Wrote out/rule_preview.md")


if __name__ == "__main__":
    main()
