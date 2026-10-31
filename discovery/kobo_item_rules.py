"""Derive the portal's Kobo item rules from the form's own score formulas (D-65–D-68).

The form scores itself in `calculate` fields named dqa_<a|c|v>_<m|q>_<group>_p ("points"). Each is
a sum of `if(…)` terms, and each term is one scored item. This reads every term and writes what the
portal needs to turn a submission's answers into pass / fail / N/A items, exactly as the form
counts them:

  availability  if([gates and] ${q} = 'yes', 2, 0)            → kind 'choice' on q
  consistency   [if(gates,] if(${q} != '', ${q}, 1.5)[, 0)]   → kind 'count3' on q (0–3)
  validity      if(${a} != '' and … and ${a} = ${b} [and ${a} = ${c}], 10, 0) → kind 'match' on a, b[, c]

A gate is `selected(${service}, 'yes')` (the service is offered; D-66) or
`selected(${review_quarter}, 'qN')` (the quarter the item is about). The month of a quarterly item
comes from the month note before it in the form; a monthly item covers the visit's review_month.

Reads data/forms/kobo/<version uid>.content.json (kobo_forms.py), the newest by default. Writes
out/edqa_item_rules.kobo.draft.php (the shape of config/edqa_item_rules.php) and
out/kobo_item_rules.md (counts, and every term it could not read: those are not guessed), and
data/forms/kobo/facility_choices.csv (the form's facility slugs with their names, wards and LGAs,
and an empty facility_code column: filled in and signed off, it is what
edqa:facilities:source-refs loads, D-72).

Usage: python kobo_item_rules.py [--version UID] [--name kobo-2024010101]
"""

from __future__ import annotations

import argparse
import csv
import json
import re
import sys
from collections import Counter
from datetime import datetime
from pathlib import Path

from kobo_form_diff import FORMS_DIR, MONTHS, OUT_DIR, rows_with_paths, text

TERM = re.compile(r"if\(")
VAR = re.compile(r"\$\{([A-Za-z0-9_]+)\}")
SERVICE = re.compile(r"selected\(\$\{([A-Za-z0-9_]+)\},\s*'yes'\)")
QUARTER = re.compile(r"selected\(\$\{review_quarter\},\s*'q([1-4])'\)")
DIMENSIONS = {"a": "availability", "c": "consistency", "v": "validity"}


def top_level_ifs(formula: str) -> list[str]:
    """Each outermost if(…) of a sum, as its text."""
    terms, depth, start, i = [], 0, None, 0
    while i < len(formula):
        if depth == 0 and formula.startswith("if(", i):
            start, depth, i = i, 1, i + 3
            continue
        ch = formula[i]
        if start is not None:
            if ch == "(":
                depth += 1
            elif ch == ")":
                depth -= 1
                if depth == 0:
                    terms.append(formula[start:i + 1])
                    start = None
        i += 1
    return terms


def read_term(term: str, dimension: str, questions: set[str], services: set[str]) -> dict | None:
    names = [v for v in VAR.findall(term)]
    gates_service = [s for s in SERVICE.findall(term) if s in services]
    quarter = QUARTER.search(term)
    items = [v for v in dict.fromkeys(names) if v in questions and v not in services and v != "review_quarter"]
    if dimension == "availability":
        m = re.search(r"\$\{([A-Za-z0-9_]+)\}\s*=\s*'yes'\s*\)?\s*,\s*2\s*,\s*0\)$", term)
        if not m or m.group(1) not in questions:
            return None
        return {"kind": "choice", "questions": [m.group(1)], "service": gates_service[0] if gates_service else None,
                "quarter": int(quarter.group(1)) if quarter else None}
    if dimension == "consistency":
        m = re.search(r"if\(\$\{([A-Za-z0-9_]+)\}\s*!=\s*''\s*,\s*\$\{\1\}\s*,\s*1\.5\)", term)
        if not m or m.group(1) not in questions:
            return None
        return {"kind": "count3", "questions": [m.group(1)], "service": gates_service[0] if gates_service else None,
                "quarter": int(quarter.group(1)) if quarter else None}
    # validity: the numbers compared for equality, 10 points when they all match.
    if not re.search(r",\s*10\s*,\s*0\)$", term) or not 2 <= len(items) <= 3:
        return None
    equal = re.findall(r"\$\{([A-Za-z0-9_]+)\}\s*=\s*\$\{([A-Za-z0-9_]+)\}", term)
    linked = {x for pair in equal for x in pair}
    if set(items) != linked:
        return None
    return {"kind": "match", "questions": items, "service": gates_service[0] if gates_service else None,
            "quarter": int(quarter.group(1)) if quarter else None}


def php(value: object, indent: int = 0) -> str:
    pad = " " * indent
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, str):
        return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"
    if isinstance(value, list):
        return "[" + ", ".join(php(v) for v in value) + "]"
    if isinstance(value, dict):
        return "[" + ", ".join(f"{php(k)} => {php(v)}" for k, v in value.items()) + "]"
    raise TypeError(type(value))


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    p.add_argument("--version", help="the version uid to read (default: the newest file)")
    p.add_argument("--name", default="kobo-2024010101", help="the map name the rules belong to")
    args = p.parse_args()
    files = sorted(FORMS_DIR.glob("*.content.json"), key=lambda f: f.stat().st_mtime)
    index = OUT_DIR / "kobo_form_versions.json"
    if not args.version and index.is_file():
        args.version = json.loads(index.read_text(encoding="utf-8"))["versions"][-1]["version"]
    path = FORMS_DIR / f"{args.version}.content.json" if args.version else (files[-1] if files else None)
    if path is None or not path.is_file():
        sys.exit(f"No version content in {FORMS_DIR}; run kobo_forms.py first")
    content = json.loads(path.read_text(encoding="utf-8"))

    rows = rows_with_paths(content)
    path_of = {p.rsplit("/", 1)[-1]: p for p, _ in rows}
    kind_of = {p.rsplit("/", 1)[-1]: str(r.get("type")) for p, r in rows}
    questions = {n for n, k in kind_of.items() if k in ("select_one", "integer")}
    services = {p.rsplit("/", 1)[-1] for p, _ in rows if p.startswith("services/")}
    month_of: dict[str, str | None] = {}
    current = None
    for p, r in rows:
        if r.get("type") == "note":
            label = re.sub(r"<[^>]+>|\$\{[^}]+\}", " ", text(r.get("label"))).strip().lower()
            m = re.match(r"(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b", label)
            if m:
                current = m.group(1)
        month_of[p.rsplit("/", 1)[-1]] = current

    rules: list[dict] = []
    unread: list[tuple[str, str]] = []
    seen: set[tuple] = set()
    for p, r in rows:
        name = p.rsplit("/", 1)[-1]
        # The points formulas only: `…_p`, or a quarterly part `…_q_p<n>`; never the maximum (`_m`,
        # `_n_p<n>`) or a count of answered items (`_a_p<n>`).
        m = re.match(r"^dqa_([acv])_([mq])_(.+?)(?:_q)?_p\d*$", name)
        if r.get("type") != "calculate" or not m or name.endswith("_pts") or re.search(r"_(?:n|a)_p\d+$", name):  # _n_p: maxima; _a_p: answered counts
            continue
        dimension, mode = DIMENSIONS[m.group(1)], "monthly" if m.group(2) == "m" else "quarterly"
        for term in top_level_ifs(text(r.get("calculation"))):
            item = read_term(term, dimension, questions, services)
            if item is None:
                if VAR.search(term) and not re.fullmatch(r"\s*if\(\$\{[a-z_]+\}\s*>\s*0.*", term):
                    unread.append((name, term[:160]))
                continue
            key = (dimension, mode, tuple(item["questions"]))
            if key in seen:
                continue
            seen.add(key)
            first = item["questions"][0]
            month = month_of.get(first) if mode == "quarterly" else None
            if mode == "quarterly" and month is None and item["quarter"]:
                unread.append((name, f"no month note before {first}"))
                continue
            rules.append({
                "dimension": dimension, "mode": mode,
                "slot": (MONTHS.index(month) % 3 + 1) if month else None,
                "quarter": item["quarter"] or ((MONTHS.index(month) // 3 + 1) if month else None),
                "kind": item["kind"],
                "code": "_".join(item["questions"]) if item["kind"] == "match" else first,
                "paths": [path_of[q].replace("/", ".") for q in item["questions"]],
                "service": path_of[item["service"]].replace("/", ".") if item["service"] else None,
            })

    counts = Counter((r["dimension"], r["mode"], r["kind"]) for r in rules)
    lines = [
        "<?php", "", "declare(strict_types=1);", "",
        "// Item rules for KoboToolbox forms (ARCHITECTURE.md §5.5, D-65–D-68): how each scored question, or",
        "// group of questions, becomes a pass / fail / N/A item. GENERATED by discovery/kobo_item_rules.py",
        f"// from the form's own score formulas ({path.name}, {datetime.now():%Y-%m-%d}); regenerate, do not edit.",
        "// kind: choice = the rule version's choice map (yes → pass, no → fail); count3 = an answer k of 0–3",
        "// is k passes of 3 checks; match = all the numbers entered and equal (none entered: N/A).",
        "// service: the services question that must be 'yes', else N/A. slot / quarter: for a quarterly",
        "// visit, the month slot and quarter the item is about; null for a monthly item (the visit's month).", "",
        "return [", f"    {php(args.name)} => [",
    ]
    for r in rules:
        lines.append(f"        {php(r)},")
    lines += ["    ],", "];", ""]
    (OUT_DIR / "edqa_item_rules.kobo.draft.php").write_text("\n".join(lines), encoding="utf-8")

    report = [
        "# Kobo item rules", "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/kobo_item_rules.py` from `{path.name}`: "
        f"{len(rules)} items read from the form's own score formulas.", "",
        "| Dimension | Mode | Kind | Items |", "|---|---|---|---|",
    ]
    report += [f"| {d} | {m} | {k} | {n} |" for (d, m, k), n in sorted(counts.items())]
    report += ["", f"Gated by a service: {sum(r['service'] is not None for r in rules)}. "
               f"Quarterly items with a month slot: {sum(r['slot'] is not None for r in rules if r['mode'] == 'quarterly')} of "
               f"{sum(r['mode'] == 'quarterly' for rules_ in [rules] for r in rules_)}.", "",
               f"## Terms not read ({len(unread)})", "",
               "Never guessed: each is left out, so a submission that depends on one is read without it." if unread else "None: every scoring term was read."]
    report += [f"- `{n}`: `{t}`" for n, t in unread[:60]]
    (OUT_DIR / "kobo_item_rules.md").write_text("\n".join(report) + "\n", encoding="utf-8")

    # The form's facility choices (D-72), for facility_reconcile.py and the client's sign-off: the slug
    # the form submits, its label, and the ward and LGA it is listed under. Facility names, not answers.
    by_list: dict[str, dict[str, dict]] = {}
    for choice in content.get("choices", []):
        by_list.setdefault(text(choice.get("list_name")), {})[text(choice.get("name"))] = choice
    wards = by_list.get("ward", {})
    with (FORMS_DIR / "facility_choices.csv").open("w", newline="", encoding="utf-8") as out:
        writer = csv.writer(out)
        writer.writerow(["ref", "facility_name", "ward", "lga", "facility_code"])
        for slug, choice in by_list.get("healthfacility", {}).items():
            ward = text(choice.get("ward_healthfacility"))
            writer.writerow([slug, text(choice.get("label")), ward, text(wards.get(ward, {}).get("lga_ward")), ""])
    print(f"{len(rules)} item rules, {len(unread)} terms not read. Wrote out/edqa_item_rules.kobo.draft.php, "
          f"out/kobo_item_rules.md, data/forms/kobo/facility_choices.csv")


if __name__ == "__main__":
    main()
