"""Diff every deployed KoboToolbox version, check each against the form contract, and draft the
Kobo field maps. Local files only; no network calls.

Reads data/forms/kobo/<version uid>.content.json and .xlsx (kobo_forms.py) and
out/kobo_form_versions.json (kobo_inventory.py, for the deployment dates). Uses field_diff.py's
XLSForm reader and drift detection, and adds what a Kobo form needs on credentials day:

  - the question-path × version matrix (by group, and every path not in all versions);
  - per version: added, removed and type-changed questions, likely renames with their similarity,
    and the changes field_diff does not see (formulas, relevance, constraints, labels, choices);
  - the form's own scoring: the questions its score calculations (dqa_<a|c|v>_<m|q>_…) read, by
    dimension, with how each kind is scored;
  - the form contract (ARCHITECTURE.md §5.1) per version, with the offending paths;
  - the comparison with the ODK contract (config/edqa_field_maps.php's xls-2026.1, and any ODK
    XForms in data/forms/) for Q-16;
  - the questions to ask the client.

Writes out/kobo_form_diff.md (no answers: names, types, counts), out/kobo_form_diff.json (not
committed) and out/edqa_field_maps.kobo.draft.php (the 'maps' + 'sources' shape of §5.5).

Usage: python kobo_form_diff.py
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path

from rich.console import Console

from field_diff import CONTRACT_RE, diff_versions, parse_xlsform

HERE = Path(__file__).resolve().parent
OUT_DIR = HERE / "out"
FORMS_DIR = HERE / "data" / "forms" / "kobo"
CONFIG = HERE.parent / "config" / "edqa_field_maps.php"
console = Console()

DIMENSIONS = {"a": "availability", "c": "consistency", "v": "validity"}
MODES = {"m": "monthly", "q": "quarterly"}
MONTHS = ["jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"]
ATTRS = ["type", "select_from_list_name", "relevant", "calculation", "constraint", "required",
         "choice_filter", "appearance", "label"]
STRUCTURE = {"begin_group", "end_group", "begin_repeat", "end_repeat"}
SHOW = 12
# Questions the score formulas test as conditions, not as items: the visit's mode and period.
CONTROL_LISTS = {"visit_period", "review_quarter", "review_month", "data_demand", "field_visit"}


# ------------------------------------------------------------------------------------ reading


def rows_with_paths(content: dict) -> list[tuple[str, dict]]:
    """(path as the data names it, row) for every survey row that is not a group marker."""
    out, stack = [], []
    for row in content.get("survey", []):
        kind = str(row.get("type", ""))
        name = str(row.get("name") or row.get("$autoname") or "")
        if kind in ("begin_group", "begin_repeat"):
            stack.append(name)
            continue
        if kind in ("end_group", "end_repeat"):
            if stack:
                stack.pop()
            continue
        out.append(("/".join([*stack, name]), row))
    return out


def text(value: object) -> str:
    if isinstance(value, list):
        return str(value[0]) if value else ""
    return "" if value is None else str(value)


def choices_by_list(content: dict) -> dict[str, list[str]]:
    lists: dict[str, list[str]] = defaultdict(list)
    for row in content.get("choices", []):
        lists[text(row.get("list_name"))].append(text(row.get("name") or row.get("$autovalue")))
    return lists


def month_slot(month: str | None) -> int | None:
    return None if month is None else MONTHS.index(month) % 3 + 1


def scoring(content: dict) -> dict:
    """The questions the form's own score calculations read, by dimension and mode, with the
    month each one is about (the month note before it) and how the form scores it."""
    paths = {p.rsplit("/", 1)[-1]: (p, r) for p, r in rows_with_paths(content)}
    month_of: dict[str, str | None] = {}
    current: str | None = None
    for path, row in rows_with_paths(content):
        if row.get("type") == "note":
            label = re.sub(r"<[^>]+>|\$\{[^}]+\}", " ", text(row.get("label"))).strip().lower()
            m = re.match(r"(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b", label)
            if m:
                current = m.group(1)
        month_of[path] = current
    items: dict[str, dict[str, dict]] = defaultdict(dict)
    for path, row in rows_with_paths(content):
        name = path.rsplit("/", 1)[-1]
        m = re.match(r"^dqa_([acv])_([mq])_", name)
        if row.get("type") != "calculate" or not m:
            continue
        key = f"{DIMENSIONS[m.group(1)]}/{MODES[m.group(2)]}"
        for ref in re.findall(r"\$\{([A-Za-z0-9_]+)\}", text(row.get("calculation"))):
            if ref not in paths:
                continue
            p, r = paths[ref]
            kind = str(r.get("type"))
            if kind not in ("select_one", "integer") or p.startswith("services/") \
                    or r.get("select_from_list_name") in CONTROL_LISTS:
                continue
            mode = MODES[m.group(2)]
            month = month_of.get(p) if mode == "quarterly" else None
            items[key][p] = {"type": kind + (f":{r.get('select_from_list_name')}" if r.get("select_from_list_name") else ""),
                             "month": month, "slot": month_slot(month) if mode == "quarterly" else None,
                             "group": p.rsplit("/", 1)[0] if "/" in p else ""}
    return items


# ------------------------------------------------------------------------------------ checks


def contract(content: dict, scored: dict[str, dict[str, dict]], fv) -> list[dict]:
    """The §5.1 form contract, each requirement with its result and the offending paths."""
    lists = choices_by_list(content)
    rows = rows_with_paths(content)
    by_leaf = {p.rsplit("/", 1)[-1]: (p, r) for p, r in rows}
    checks: list[dict] = []

    def add(result: str, requirement: str, detail: str, offending: list[str] | None = None) -> None:
        checks.append({"result": result, "requirement": requirement, "detail": detail, "offending": offending or []})

    facility = next((by_leaf[n] for n in ("facility_code", "healthfacility", "health_facility", "facility") if n in by_leaf), None)
    if facility is None:
        add("FAIL", "Facility chosen by cascading select LGA → ward → facility, from a CSV media file",
            "no facility question found by name")
    else:
        p, r = facility
        from_file = str(r.get("type", "")).endswith("_from_file")
        listed = text(r.get("select_from_list_name"))
        detail = (f"`{p}` is {r.get('type')}"
                  + (f" from the form's own choice list `{listed}` ({len(lists.get(listed, []))} choices)" if listed and not from_file else "")
                  + (f", filtered by `{r.get('choice_filter')}`" if r.get("choice_filter") else ", not filtered by an earlier answer"))
        ok = from_file and bool(r.get("choice_filter"))
        add("PASS" if ok else "FAIL", "Facility chosen by cascading select LGA → ward → facility, from a CSV media file",
            detail + ("" if ok else ". It cascades, but from choices inside the form, not a CSV file the portal "
                      "generates, so the portal's master list cannot keep it current"),
            [] if ok else [p] + [by_leaf[n][0] for n in ("lga", "ward") if n in by_leaf])

    all_scored = {p: v for d in scored.values() for p, v in d.items()}
    off_name = sorted(p for p in all_scored if not CONTRACT_RE.match(p.rsplit("/", 1)[-1]))
    add("PASS" if all_scored and not off_name else "FAIL", "Scored question names match `^(avail|consist|valid)_m[1-3]_[a-z0-9_]+$`",
        f"{len(all_scored)} questions feed the form's own scores; {len(off_name)} do not follow the pattern"
        if all_scored else "the form's score calculations read no question", off_name)

    off_type = []
    for p, v in sorted(all_scored.items()):
        listed = v["type"].split(":", 1)[1] if ":" in v["type"] else ""
        if not v["type"].startswith("select_one") or set(lists.get(listed, [])) != {"yes", "no", "na"}:
            off_type.append(p)
    kinds = Counter(v["type"] for v in all_scored.values())
    add("PASS" if all_scored and not off_type else "FAIL", "Scored questions are `select_one` yes / no / na",
        "; ".join(f"{n} are {k}" + (f" ({'/'.join(lists.get(k.split(':', 1)[1], []))})" if ':' in k else "") for k, n in kinds.most_common()),
        off_type)

    for want, found in (("round_year", ("round_year", "review_year")), ("round_quarter", ("round_quarter", "review_quarter"))):
        hit = next((by_leaf[n] for n in found if n in by_leaf), None)
        if hit is None:
            add("FAIL", f"`{want}` is a read-only calculated field", "not in the form")
            continue
        p, r = hit
        ok = r.get("type") == "calculate"
        add("PASS" if ok else "FAIL", f"`{want}` is a read-only calculated field",
            f"`{p}` is {r.get('type')} ({text(r.get('select_from_list_name')) or 'no list'}): "
            + ("calculated" if ok else "chosen by the assessor"), [] if ok else [p])

    add("PASS" if "meta/instanceID" in fv.questions else "FAIL", "`meta/instanceID` present",
        "added by Kobo to every deployed form (rows carry `meta/instanceID`)")
    for param in ("start", "end"):
        hit = next(((p, r) for p, r in rows if r.get("type") == param), None)
        add("PASS" if hit else "FAIL", f"`{param}` metadata field", f"`{hit[0]}`" if hit else "missing", [] if hit else [param])
    gps = [p for p, r in rows if r.get("type") == "geopoint"]
    photo = [p for p, r in rows if r.get("type") == "image"]
    add("INFO", "GPS and register photo optional", f"GPS: {', '.join(gps) or 'none'}; photo: {', '.join(photo) or 'none'}")
    return checks


def deep_diff(a: dict, b: dict) -> dict:
    """What changed between two versions beyond the question list: attributes per path, choices."""
    ra = {p: r for p, r in rows_with_paths(a)}
    rb = {p: r for p, r in rows_with_paths(b)}
    changed: dict[str, list[str]] = defaultdict(list)
    for p in sorted(set(ra) & set(rb)):
        for attr in ATTRS:
            if text(ra[p].get(attr)) != text(rb[p].get(attr)):
                changed[attr].append(p)
    ca, cb = choices_by_list(a), choices_by_list(b)
    choice_changes = {}
    for name in sorted(set(ca) | set(cb)):
        added, removed = sorted(set(cb.get(name, [])) - set(ca.get(name, []))), sorted(set(ca.get(name, [])) - set(cb.get(name, [])))
        if added or removed:
            choice_changes[name] = {"added": added, "removed": removed}
    settings = {k: (text((a.get("settings") or {}).get(k)), text((b.get("settings") or {}).get(k)))
                for k in ("version", "id_string", "name") if text((a.get("settings") or {}).get(k)) != text((b.get("settings") or {}).get(k))}
    return {"attributes": dict(changed), "choices": choice_changes, "settings": settings}


def odk_contract() -> dict[str, str]:
    """The ODK contract's identity paths, from config/edqa_field_maps.php's xls-2026.1 map."""
    if not CONFIG.is_file():
        return {}
    block = CONFIG.read_text(encoding="utf-8").split("'xls-2026.1' => [", 1)[-1].split("],", 1)[0]
    return dict(re.findall(r"'([a-z_]+)' => '([^']+)'", block))


# ------------------------------------------------------------------------------------ output


def listing(paths: list[str], n: int = SHOW) -> str:
    shown = ", ".join(f"`{p}`" for p in paths[:n])
    return shown + (f" … and {len(paths) - n} more" if len(paths) > n else "")


def php(value: object) -> str:
    if value is None:
        return "null"
    if isinstance(value, bool):
        return "true" if value else "false"
    if isinstance(value, int):
        return str(value)
    return "'" + str(value).replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> None:
    argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter).parse_args()
    contents = {p.name.removesuffix(".content.json"): json.loads(p.read_text(encoding="utf-8"))
                for p in FORMS_DIR.glob("*.content.json")}
    if not contents:
        sys.exit(f"No versions in {FORMS_DIR}; run kobo_forms.py first")
    dates: dict[str, str] = {}
    index = OUT_DIR / "kobo_form_versions.json"
    if index.is_file():
        dates = {v["version"]: v.get("publishedAt") or "" for v in json.loads(index.read_text(encoding="utf-8"))["versions"]}
    order = sorted(contents, key=lambda uid: (dates.get(uid, ""), uid))
    forms = {uid: parse_xlsform(FORMS_DIR / f"{uid}.xlsx", dates.get(uid, "")) for uid in order}
    current = order[-1]
    scored = {uid: scoring(contents[uid]) for uid in order}
    # Every version is checked against the items the current one scores: v1 has no score formulas yet,
    # and its questions are the same.
    checks = {uid: contract(contents[uid], {k: {p: v for p, v in d.items() if p in dict(rows_with_paths(contents[uid]))}
                                            for k, d in scored[current].items()}, forms[uid]) for uid in order}
    steps = [(a, b, diff_versions(forms[a], forms[b]), deep_diff(contents[a], contents[b])) for a, b in zip(order, order[1:])]

    # The matrix: every path, which versions have it. Paths in all versions are counted by group.
    presence: dict[str, list[bool]] = {}
    kinds: dict[str, str] = {}
    for i, uid in enumerate(order):
        for path, row in rows_with_paths(contents[uid]):
            presence.setdefault(path, [False] * len(order))[i] = True
            kinds[path] = str(row.get("type"))
    everywhere = [p for p, v in presence.items() if all(v)]
    partial = sorted(p for p, v in presence.items() if not all(v))
    by_group = Counter(p.split("/", 1)[0] if "/" in p else "(top level)" for p in everywhere)

    settings_versions = sorted({text((contents[u].get("settings") or {}).get("version")) for u in order})
    odk = odk_contract()
    odk_forms = sorted((HERE / "data" / "forms").glob("*.xml"))
    cur_rows = {p.rsplit("/", 1)[-1]: p for p, _ in rows_with_paths(contents[current])}

    # ---- the markdown report
    short = {uid: f"v{i + 1}" for i, uid in enumerate(order)}
    L = ["# KoboToolbox form diff and contract check", "",
         f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/kobo_form_diff.py` from {len(order)} deployed "
         f"version(s) in `data/forms/kobo/` (the content Kobo keeps per version, "
         f"`GET /api/v2/assets/{{uid}}/versions/{{version}}/`). Names, types and counts only; no answers.", "",
         "## Summary", ""]
    fails = [c for c in checks[current] if c["result"] == "FAIL"]
    answered = {uid: {p for p, r in rows_with_paths(contents[uid]) if r.get('type') not in ('calculate', 'note')} for uid in order}
    same_questions = all(answered[u] == answered[current] for u in order)
    behaviour = [(short[b], attr, len(paths)) for a, b, d, deep in steps for attr, paths in deep['attributes'].items()
                 if attr in ('relevant', 'required', 'constraint', 'choice_filter') and any(not p.rsplit('/', 1)[-1].startswith('dqa_') for p in paths)]
    no_version = [short[u] for u in order if not text((contents[u].get('settings') or {}).get('version'))]
    L += [f"- **The form does not meet the portal's form contract** (ARCHITECTURE.md §5.1): {len(fails)} of "
          f"{sum(c['result'] != 'INFO' for c in checks[current])} requirements fail in the current version. The "
          "identity fields can be mapped; the scored questions cannot be read by the portal's parser as they are.",
          (f"- **The questions an assessor answers are the same in all {len(order)} versions**" if same_questions else
           f"- **The questions an assessor answers differ between versions**") + " (see the matrix): the paths a map "
          "names are the same, so one map can name them all. What changed: the form's own score formulas (v2, v3) "
          + ("and when questions show or must be answered: " + "; ".join(f"{v}: `{a}` on {n}" for v, a, n in behaviour)
             + ". Those change which items an assessor answers, so whether one scoring reading fits every version is to "
             "CONFIRM." if behaviour else "and wording."),
          f"- XLSForm version in the settings: `{', '.join(v for v in settings_versions if v) or 'none'}`"
          + (f" from {short[order[len(no_version)]]} on; {', '.join(no_version)} carry none" if no_version else "")
          + ". Kobo labels each deployment with its own uid, so only the uid tells the versions apart.", ""]

    L += ["## Versions", "", "| | Version uid | Deployed | Questions | Calculations | XLSForm version |", "|---|---|---|---|---|---|"]
    for uid in order:
        rows = rows_with_paths(contents[uid])
        L.append(f"| {short[uid]} | `{uid}` | {dates.get(uid, '')[:10]} | {sum(r.get('type') not in ('calculate', 'note') for _, r in rows)} | "
                 f"{sum(r.get('type') == 'calculate' for _, r in rows)} | `{text((contents[uid].get('settings') or {}).get('version'))}` |")

    L += ["", "## Question-path × version matrix", "",
          f"{len(presence)} paths in all; {len(everywhere)} are in every version. By top-level group, the paths "
          "present in every version:", "", "| Group | Paths | " + " | ".join(short[u] for u in order) + " |",
          "|---|---|" + "---|" * len(order)]
    for group, n in by_group.most_common():
        L.append(f"| `{group}` | {n} | " + " | ".join("✓" for _ in order) + " |")
    partial_calc = [p for p in partial if kinds[p] == 'calculate']
    partial_q = [p for p in partial if kinds[p] != 'calculate']
    L += ["", f"The {len(partial)} paths not in every version: {len(partial_calc)} are the form's own score "
          f"calculations, {len(partial_q)} are questions or notes. By kind:", "",
          "| Kind | Paths | " + " | ".join(short[u] for u in order) + " |", "|---|---|" + "---|" * len(order)]
    for label, group in (("score calculations", partial_calc), ("questions and notes", partial_q)):
        if group:
            L.append(f"| {label} | {len(group)} | " + " | ".join(str(sum(presence[p][i] for p in group)) for i in range(len(order))) + " |")
    if partial_q:
        L += ["", "| Question | Type | " + " | ".join(short[u] for u in order) + " |", "|---|---|" + "---|" * len(order)]
        L += [f"| `{p}` | {kinds[p]} | " + " | ".join("✓" if x else "–" for x in presence[p]) + " |" for p in partial_q]
    L.append("")
    L.append("The full matrix, every path × version, is in `out/kobo_form_diff.json` (`matrix`).")

    L += ["", "## Changes per version", ""]
    for a, b, d, deep in steps:
        L += [f"### {short[a]} → {short[b]} (`{a}` → `{b}`)", "",
              f"{len(d['added'])} added, {len(d['removed'])} removed, {len(d['changed'])} changed type or choices, "
              f"{len(d['renames'])} likely renames.", ""]
        for label, paths in (("Added", d["added"]), ("Removed", d["removed"]), ("Changed type or choices", d["changed"])):
            if paths:
                L.append(f"- **{label}:** {listing(paths)}")
        if d["renames"]:
            L += ["", "| Old | New | Similarity (0–100) |", "|---|---|---|"]
            L += [f"| `{o}` | `{n}` | {s:.0f} |" for o, n, s in d["renames"]]
        for attr, paths in deep["attributes"].items():
            L.append(f"- **{attr} changed** on {len(paths)}: {listing(paths, 6)}")
        for name, ch in deep["choices"].items():
            L.append(f"- **Choice list `{name}`:** {len(ch['added'])} added, {len(ch['removed'])} removed"
                     + (f" (removed: {listing(ch['removed'], 5)})" if ch["removed"] else "")
                     + (f" (added: {listing(ch['added'], 5)})" if ch["added"] else ""))
        for k, (x, y) in deep["settings"].items():
            L.append(f"- **Settings `{k}`:** `{x}` → `{y}`")
        if not any((d["added"], d["removed"], d["changed"], deep["attributes"], deep["choices"], deep["settings"])):
            L.append("- No difference in questions, formulas, labels or choices (only Kobo's own metadata).")
        L.append("")

    L += ["## How the form scores itself", "",
          "The form computes its own scores in `calculate` fields named `dqa_<a|c|v>_<m|q>_…` (availability, "
          "consistency, validity; monthly or quarterly). The portal never takes a score from a submission "
          "(CLAUDE.md hard rule 2), but these formulas show which questions are the scored items and how the "
          "form's authors meant them to count:", "",
          "| Dimension / mode | Items | Item type | How the form scores an item |", "|---|---|---|---|"]
    rules = {"availability": "`yes` = 2 points of 2; the item is left out when the service is not offered (`services/*`)",
             "consistency": "the answer (0–3 of three clients correct) is the points, of 3; unanswered = 1.5 of 1.5",
             "validity": "DHIS = MSF = register (all three entered and equal) = 10 points, else 0"}
    for key, items in sorted(scored[current].items()):
        types = Counter(v["type"] for v in items.values())
        count = f"{len(items)} numbers (≈ {len(items) // 3} three-way comparisons)" if key.startswith('validity') else str(len(items))
        L.append(f"| {key} | {count} | {', '.join(f'{t} ({n})' for t, n in types.most_common())} | {rules[key.split('/')[0]]} |")
    q_items = [v for k, d in scored[current].items() if k.endswith("quarterly") for v in d.values()]
    L += ["", f"Quarterly items carry their month in the note before them (\"January\", \"February\" …); "
          f"{sum(v['month'] is not None for v in q_items)} of {len(q_items)} quarterly items have one, which gives "
          "the portal's month slot (January, April, July, October = slot 1). Monthly items cover the one month "
          "chosen in `review_month`.", ""]

    L += ["## Form contract (ARCHITECTURE.md §5.1)", ""]
    for uid in reversed(order):
        same_as = next((short[u] for u in order if u != uid and [ (c['result'], c['detail']) for c in checks[u]] == [(c['result'], c['detail']) for c in checks[uid]] and order.index(u) > order.index(uid)), None)
        L += [f"### {short[uid]} `{uid}`" + (f": the same as {same_as}" if same_as else ""), ""]
        if same_as:
            continue
        L += ["| Result | Requirement | Detail | Offending paths |", "|---|---|---|---|"]
        for c in checks[uid]:
            L.append(f"| **{c['result']}** | {c['requirement']} | {c['detail'].replace('|', '/')} | "
                     + (f"{len(c['offending'])}: {listing(c['offending'], 8)}" if c["offending"] else "") + " |")
        L.append("")

    L += ["## Is this the same XLSForm as the ODK contract? (Q-16)", ""]
    if odk_forms:
        L.append(f"ODK XForms found in `data/forms/`: {', '.join(f.name for f in odk_forms)}. Compare with `field_diff.py`.")
    else:
        L.append("No ODK Central form has been downloaded yet (`data/forms/*.xml` is empty: ODK credentials have not "
                 "arrived), so the comparison is with the ODK contract the portal was built to "
                 "(`config/edqa_field_maps.php`, map `xls-2026.1`):")
    L += ["", "| Field | ODK contract path | This Kobo form |", "|---|---|---|"]
    kobo_names = {"facility_code": "healthfacility", "lga_code": "lga", "ward_code": "ward", "round_year": "review_year",
                  "round_quarter": "review_quarter", "started_at": "start", "ended_at": "end", "assessor": "name_ass", "gps": "location"}
    for field, path in odk.items():
        if field in ("scored_prefix_pattern",):
            continue
        k = kobo_names.get(field)
        L.append(f"| {field} | `{path}` | " + (f"`{cur_rows[k]}`" if k and k in cur_rows else "**no such question**") + " |")
    L += [f"| scored items | `{odk.get('scored_prefix_pattern', '')}`, yes/no/na | none follow it: {sum(len(d) for d in scored[current].values())} "
          "items in three shapes (yes/no; a 0–3 count; three numbers to compare) |", "",
          "**No.** It is a different form from the one the contract describes: other group and question names, "
          "the review period chosen by the assessor, and items that are not yes/no/na. "
          "If the client also collects on ODK Central, ask whether it is this same XLSForm there (Q-16).", ""]

    inventory = json.loads((OUT_DIR / "kobo_inventory.json").read_text(encoding="utf-8")) if (OUT_DIR / "kobo_inventory.json").is_file() else {}
    asset = inventory.get("asset") or {}
    per_q = ", ".join(f"{q}: {n}" for q, n in (asset.get("submissions_per_quarter") or {}).items()) or "unknown"
    facilities = len(choices_by_list(contents[current]).get("healthfacility", []))
    questions = [
        f"**Which form is the one the portal reports on?** This asset has {asset.get('submission_count', '?')} submissions ({per_q}) and {len(order)} deployments between {dates.get(order[0], '')[:10]} and {dates.get(current, '')[:10]}. Is it a pilot of the real form, and is it the form deployed on ODK Central too (Q-16)?",
        "**Adapt the form, or the portal?** The contract (§5.1) asks for yes/no/na items named `(avail|consist|valid)_m[1-3]_…`. Adapting the XLSForm keeps the portal's scoring as built; reading this form as it is means new parser rules and decisions below. Which does the client want?",
        "**Availability:** is a `no` a fail and a `yes` a pass, with an item that has no answer because the service is not offered counted as not applicable (not a fail)?",
        "**Consistency:** each item is a count of three clients correct (0–3). How should 0, 1, 2 and 3 count? The form gives the count as points out of 3 (so 2 of 3 is two-thirds of a pass); the portal counts whole items. And an unanswered consistency item scores 1.5 of 1.5 in the form, a full pass: is that intended?",
        "**Validity:** a month passes in the form only when DHIS, MSF and the register agree exactly (10 points, else 0). Is exact equality the rule, or is a tolerance allowed? Is an item with one of the three numbers missing a fail or not applicable?",
        "**Weights:** the form weights items differently (2, 3 and 10 points). The portal's published scoring is an unweighted mean of items passed per dimension and month. Which is the published method?",
        "**Monthly and quarterly DQA in one form:** which visits does the portal count, quarterly only, or monthly too? A monthly visit covers one month of the quarter: does it make a round's assessment on its own?",
        "**The round:** the assessor chooses `review_year`, `review_quarter` (or `review_month`). Can these become calculated, read-only fields (the contract), or is the assessor's choice the round? What if it disagrees with the visit date?",
        f"**Facilities:** the facility is chosen from the form's own list `healthfacility` ({facilities} choices) with names like `abcdef_ghijk_lmnop_1`, not the master list's codes. Can the form use the portal's CSV media file (LGA → ward → facility, the portal's codes)? If not, who maintains the mapping from these names to facility codes?",
        "**Action plans:** the form records follow-up action plans per dimension (`follow_up/*`: plan, responsible person, due date). Should the portal read them into its implementation plan, or is the portal's plan (generated from the scores) the one that counts?",
        "**`field_visit_type`, `ddu_dqa`:** community visits and DDU at LGA level are in the same form. Are those excluded from facility scores?",
    ]
    L += ["## Questions for the client", ""] + [f"{i}. {q}" for i, q in enumerate(questions, 1)] + [""]
    (OUT_DIR / "kobo_form_diff.md").write_text("\n".join(L) + "\n", encoding="utf-8")

    (OUT_DIR / "kobo_form_diff.json").write_text(json.dumps({
        "generated": datetime.now().isoformat(timespec="seconds"), "versions": order,
        "matrix": {p: presence[p] for p in sorted(presence)}, "scored_items": scored[current],
        "contract": checks, "changes": [{"from": a, "to": b, "renames": d["renames"], "deep": deep} for a, b, d, deep in steps],
    }, indent=1), encoding="utf-8")

    # ---- the draft field maps, 'maps' + 'sources' (ARCHITECTURE.md §5.5; dot paths, as the app reads them)
    def dot(name: str) -> str | None:
        return cur_rows[name].replace("/", ".") if name in cur_rows else None
    map_name = f"kobo-{settings_versions[-1] or 'dqa'}"
    P = ["<?php", "",
         "// DRAFT generated by discovery/kobo_form_diff.py: not loaded by the app. The real Kobo form",
         "// (form id " + text(contents[current].get('settings', {}).get('id_string')) + ") does NOT meet the form contract (ARCHITECTURE.md §5.1):",
         "// see out/kobo_form_diff.md. Only the identity fields are mapped; the scored items cannot be read by the",
         "// current parser until the client decides (Questions for the client). Lines marked CONFIRM are inferred.", "",
         "return [", "    'maps' => [",
         f"        {php(map_name)} => [",
         f"            'facility_code' => {php(dot('healthfacility'))}, // CONFIRM: a form choice name, not a master-list code (question 9)",
         f"            'lga_code' => {php(dot('lga'))}, // CONFIRM: the form's lga list values against the 23 LGA codes",
         f"            'ward_code' => {php(dot('ward'))}, // CONFIRM: the form's ward list values against the master list",
         f"            'round_year' => {php(dot('review_year'))}, // CONFIRM: chosen by the assessor, not calculated (question 8)",
         f"            'round_quarter' => {php(dot('review_quarter'))}, // CONFIRM: values q1..q4, not 1..4 (question 8)",
         f"            'started_at' => {php(dot('start'))},",
         f"            'ended_at' => {php(dot('end'))},",
         f"            'assessor' => {php(dot('name_ass'))}, // CONFIRM: free text (\"Name of Assessor\"), as ASSESSOR_VOLUME needs it",
         f"            'gps' => {php(dot(next((p.rsplit('/', 1)[-1] for p, r in rows_with_paths(contents[current]) if r.get('type') == 'geopoint'), '')))},",
         "            // Scored items: NONE follow the contract pattern. The form's items, by the form's own scoring:",
    ]
    for key, items in sorted(scored[current].items()):
        P.append(f"            //   {key}: {len(items)} items ({', '.join(f'{t} {n}' for t, n in Counter(v['type'] for v in items.values()).most_common())})")
    P += ["            // The full list (path, dimension, mode, month slot) is in out/kobo_form_diff.json, scored_items.",
          "            'scored_prefix_pattern' => null, // CONFIRM: not mappable as built (questions 2–6)",
          "            'renames' => [],", "        ],", "    ],", "", "    'sources' => [",
          "        // __version__ (the deployment uid) => map. The versions name the same questions, but v5 and v7 changed",
          "        // when questions show or must be answered: one map for all of them is to CONFIRM (out/kobo_form_diff.md).",
          "        'kobo' => ["]
    for uid in order:
        P.append(f"            {php(uid)} => {php(map_name)}, // {short[uid]}, deployed {dates.get(uid, '')[:10]}. CONFIRM")
    P += ["        ],", "    ],", "];", ""]
    (OUT_DIR / "edqa_field_maps.kobo.draft.php").write_text("\n".join(P), encoding="utf-8")
    console.print(f"{len(order)} versions; contract: {len(fails)} FAIL in the current one. Wrote out/kobo_form_diff.md, "
                  "out/kobo_form_diff.json, out/edqa_field_maps.kobo.draft.php")


if __name__ == "__main__":
    main()
