"""Compare question names across form versions, check each against the form contract, and draft
the field map.

Answers Q-02 (cascading facility select, select_one scored items) and Q-05 (how far names drift).
Reads the XForms downloaded by form_versions.py. Local files only; no ODK calls.

Writes:
  out/field_diff.md                 per-version contract check, drift and effort
  out/field_map.draft.json          full map per version (input to rule_preview.py; not committed)
  out/edqa_field_maps.draft.php     same map in the config/edqa_field_maps.php shape (§5.5)

Everything inferred by name is flagged CONFIRM. Only start/end (from their preload) and
meta/instanceID are taken as certain.

Usage: python field_diff.py [--forms data/forms]
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass, field
from datetime import datetime
from pathlib import Path

from lxml import etree
from rapidfuzz import fuzz
from rich.console import Console

HERE = Path(__file__).resolve().parent
OUT_DIR = HERE / "out"
console = Console()

XF = "http://www.w3.org/2002/xforms"
NS = {"h": "http://www.w3.org/1999/xhtml", "xf": XF}
JR = "{http://openrosa.org/javarosa}"
CONTROL_TAGS = {"input", "select1", "select", "upload", "range", "trigger", "rank"}

CONTRACT_PATTERN = r"^(avail|consist|valid)_m([1-3])_([a-z0-9_]+)$"
CONTRACT_RE = re.compile(CONTRACT_PATTERN)
CONTRACT_CHOICES = {"yes": "pass", "no": "fail", "na": "na"}
DIMENSIONS = {"avail": "availability", "consist": "consistency", "valid": "validity"}

# Heuristic for scored questions that do not follow the contract naming.
_DIM_RE = re.compile(r"(?:^|[_/])(avail|consist|valid)[a-z]*", re.I)
_SLOT_RE = re.compile(r"(?:^|[_/])(?:m|month|mth|mon)_?([1-3])(?=$|[_/])", re.I)

LOGICAL_FIELDS: dict[str, list[str]] = {
    "facility_code": ["facility_code", "fac_code", "fac_id", "hf_code", "facility_id",
                      "facility_uid", "facility"],
    "lga_code": ["lga", "lga_code", "lga_name", "local_government", "local_govt"],
    "ward_code": ["ward", "ward_code", "ward_name"],
    "round_year": ["round_year", "year", "assessment_year"],
    "round_quarter": ["round_quarter", "quarter", "assessment_quarter"],
    "assessor": ["assessor_name", "assessor", "enumerator_name", "enumerator", "username"],
}


# ------------------------------------------------------------------------------------ parsing


@dataclass
class Question:
    path: str
    leaf: str
    bind_type: str = ""
    control: str = ""
    choices: list[str] = field(default_factory=list)
    choice_source: str = ""        # inline | instance:<id> | file:<src>
    choice_filtered: bool = False  # itemset nodeset has a predicate (cascading select)
    calculate: bool = False
    readonly: bool = False
    required: bool = False
    preload: str = ""
    mediatype: str = ""
    in_repeat: bool = False

    @property
    def kind(self) -> str:
        return self.control or ("calculate" if self.calculate else "hidden")

    def signature(self) -> tuple:
        return (self.bind_type, self.kind, tuple(sorted(self.choices)))


@dataclass
class FormVersion:
    version: str
    published: str
    title: str
    source: Path
    questions: dict[str, Question]
    repeats: list[str]


def _local(el: etree._Element) -> str:
    return etree.QName(el).localname


def _strip_root(ref: str, root: str) -> str:
    ref = ref.strip()
    prefix = f"/{root}/"
    return ref[len(prefix):] if ref.startswith(prefix) else ref.lstrip("/")


def _abs_ref(el: etree._Element, attr: str, root: str) -> str:
    """Resolve a body control's ref against enclosing group/repeat refs."""
    ref = el.get(attr, "")
    node = el.getparent()
    while ref and not ref.startswith("/") and node is not None:
        parent_ref = node.get("ref") or node.get("nodeset")
        if parent_ref:
            ref = f"{parent_ref.rstrip('/')}/{ref}"
        node = node.getparent()
    return _strip_root(ref, root)


def parse_xform(path: Path, published: str = "") -> FormVersion:
    parser = etree.XMLParser(resolve_entities=False, no_network=True, remove_comments=True)
    doc = etree.parse(str(path), parser).getroot()
    model = doc.find("h:head/xf:model", NS)
    if model is None:
        sys.exit(f"{path.name}: not an XForm (no h:head/model)")
    title = (doc.findtext("h:head/h:title", default="", namespaces=NS) or "").strip()
    instances = model.findall("xf:instance", NS)
    primary = next((i for i in instances if i.get("id") is None), None)
    if primary is None or not len(primary):
        sys.exit(f"{path.name}: no primary instance")
    data_root = next(c for c in primary if isinstance(c.tag, str))
    root = _local(data_root)
    version = data_root.get("version") or ""
    secondary = {i.get("id"): i for i in instances if i.get("id")}

    questions: dict[str, Question] = {}

    def walk(el: etree._Element, parts: list[str]) -> None:
        children = [c for c in el if isinstance(c.tag, str)]
        if el.get(f"{JR}template") is not None and parts:
            pass  # repeat template: same paths as the first instance
        if not children:
            rel = "/".join(parts)
            if rel and rel not in questions:
                questions[rel] = Question(path=rel, leaf=parts[-1])
            return
        for c in children:
            walk(c, [*parts, _local(c)])

    walk(data_root, [])

    for bind in model.iter(f"{{{XF}}}bind"):
        rel = _strip_root(bind.get("nodeset", ""), root)
        q = questions.get(rel)
        if q is None:
            continue
        q.bind_type = (bind.get("type") or "").split(":")[-1]
        q.calculate = bind.get("calculate") is not None
        q.readonly = (bind.get("readonly") or "").startswith("true")
        q.required = (bind.get("required") or "").startswith("true")
        if bind.get(f"{JR}preload"):
            q.preload = f"{bind.get(f'{JR}preload')}:{bind.get(f'{JR}preloadParams') or ''}"

    repeats: list[str] = []
    body = doc.find("h:body", NS)
    for el in body.iter() if body is not None else []:
        if not isinstance(el.tag, str):
            continue
        tag = _local(el)
        if tag == "repeat":
            repeats.append(_abs_ref(el, "nodeset", root))
            continue
        if tag not in CONTROL_TAGS:
            continue
        rel = _abs_ref(el, "ref", root)
        q = questions.get(rel)
        if q is None:
            continue
        q.control = tag
        q.mediatype = el.get("mediatype", "")
        items = el.findall("xf:item", NS)
        if items:
            q.choices = [(i.findtext("xf:value", default="", namespaces=NS) or "").strip()
                         for i in items]
            q.choice_source = "inline"
        itemset = el.find("xf:itemset", NS)
        if itemset is not None:
            nodeset = itemset.get("nodeset", "")
            q.choice_filtered = "[" in nodeset
            match = re.search(r"instance\(\s*['\"]([^'\"]+)['\"]\s*\)", nodeset)
            value_el = itemset.find("xf:value", NS)
            value_ref = value_el.get("ref", "name") if value_el is not None else "name"
            inst = secondary.get(match.group(1)) if match else None
            if inst is not None and inst.get("src"):
                q.choice_source = f"file:{inst.get('src')}"
            elif inst is not None:
                q.choice_source = f"instance:{match.group(1)}"
                value_leaf = value_ref.rsplit("/", 1)[-1]
                q.choices = [
                    next(((c.text or "").strip() for c in item
                          if isinstance(c.tag, str) and _local(c) == value_leaf), "")
                    for item in inst.iter()
                    if isinstance(item.tag, str) and _local(item) == "item"]
    for q in questions.values():
        q.in_repeat = any(q.path.startswith(r + "/") for r in repeats if r)
    return FormVersion(version, published, title, path, questions, repeats)


# ------------------------------------------------------------------------------ analysis


def dimension_slot(q: Question) -> tuple[str, int, str, bool] | None:
    """(dimension, slot, item_code, follows_contract) for a question that looks scored."""
    m = CONTRACT_RE.match(q.leaf)
    if m:
        return DIMENSIONS[m.group(1)], int(m.group(2)), m.group(3), True
    if q.kind not in ("select1", "input") or q.preload or q.calculate:
        return None
    dim = _DIM_RE.search(q.path)
    slot = _SLOT_RE.search(q.path)
    if not dim or not slot:
        return None
    code = re.sub(r"(?:^|_)(?:avail|consist|valid)[a-z]*|(?:^|_)(?:m|month|mth|mon)_?[1-3]", "",
                  q.leaf, flags=re.I).strip("_").lower() or q.leaf.lower()
    return DIMENSIONS[dim.group(1).lower()], int(slot.group(1)), code, False


def find_logical(fv: FormVersion, name: str) -> Question | None:
    leaves = {}
    for q in fv.questions.values():
        leaves.setdefault(q.leaf.lower(), q)
    for synonym in LOGICAL_FIELDS[name]:
        if synonym in leaves:
            return leaves[synonym]
    return None


def find_preload(fv: FormVersion, param: str) -> Question | None:
    for q in fv.questions.values():
        if q.preload == f"timestamp:{param}":
            return q
    return fv.questions.get(param)


@dataclass
class Check:
    status: str  # PASS | FAIL | CONFIRM | INFO
    requirement: str
    detail: str


def contract_checks(fv: FormVersion) -> tuple[list[Check], list[tuple[Question, tuple]]]:
    checks: list[Check] = []
    scored = [(q, ds) for q in fv.questions.values() if (ds := dimension_slot(q))]
    contract = [(q, ds) for q, ds in scored if ds[3]]

    fac, lga, ward = (find_logical(fv, n) for n in ("facility_code", "lga_code", "ward_code"))
    if fac is None:
        checks.append(Check("FAIL", "Facility chosen by cascading select from a CSV file",
                            "no facility question found by name — CONFIRM manually"))
    else:
        from_csv = fac.choice_source.startswith("file:") and ".csv" in fac.choice_source
        detail = (f"`{fac.path}` is {fac.kind}"
                  + (f", choices from `{fac.choice_source[5:]}`" if fac.choice_source.startswith("file:")
                     else f", choices {fac.choice_source or 'none (free text)'}")
                  + (", filtered by an earlier answer" if fac.choice_filtered else ""))
        ok = fac.kind == "select1" and from_csv and fac.choice_filtered
        checks.append(Check("PASS" if ok else "FAIL",
                            "Facility chosen by cascading select from a CSV file", detail))
    for label, q in (("LGA", lga), ("Ward", ward)):
        if q is not None:
            checks.append(Check("INFO", f"{label} question", f"`{q.path}` ({q.kind}"
                                f"{', ' + q.choice_source if q.choice_source else ''})"))

    cells = {(d, s) for _, (d, s, _, _) in contract}
    checks.append(Check(
        "PASS" if len(cells) == 9 else "FAIL",
        "Scored question names follow `(avail|consist|valid)_m[1-3]_*`",
        f"{len(contract)} questions follow the pattern, covering {len(cells)}/9 dimension × "
        f"month cells; {len(scored) - len(contract)} more look scored by name"
        + (" (CONFIRM)" if len(scored) > len(contract) else "")))

    typed = [q for q, _ in scored if q.kind == "input" or q.bind_type in ("int", "decimal")]
    bad_choice = [q for q, _ in scored if q.kind == "select1"
                  and not (q.choices and set(q.choices) <= set(CONTRACT_CHOICES))]
    ok = scored and not typed and not bad_choice
    detail = []
    if typed:
        detail.append(f"{len(typed)} scored questions are typed numbers "
                      f"(e.g. `{typed[0].path}`: {typed[0].bind_type or typed[0].kind}) — scores "
                      f"are entered, not computed")
    if bad_choice:
        seen = sorted({c for q in bad_choice for c in q.choices})[:10]
        detail.append(f"{len(bad_choice)} select_one questions use other choice values: "
                      f"{', '.join(f'`{c}`' for c in seen) or 'from an external file'}")
    checks.append(Check("PASS" if ok else ("FAIL" if scored else "CONFIRM"),
                        "Scored questions are select_one yes / no / na",
                        "; ".join(detail) or ("all of them" if scored else "no scored questions found")))

    for name in ("round_year", "round_quarter"):
        q = find_logical(fv, name)
        if q is None:
            checks.append(Check("FAIL", f"`{name}` is a read-only calculated field", "not in the form"))
        elif q.calculate and (q.readonly or not q.control):
            checks.append(Check("PASS", f"`{name}` is a read-only calculated field", f"`{q.path}`"))
        else:
            checks.append(Check("FAIL", f"`{name}` is a read-only calculated field",
                                f"`{q.path}` is typed by the assessor ({q.kind})"))

    checks.append(Check("PASS" if "meta/instanceID" in fv.questions else "FAIL",
                        "`meta/instanceID` present", ""))
    for param in ("start", "end"):
        q = find_preload(fv, param)
        checks.append(Check("PASS" if q is not None and q.preload else "FAIL",
                            f"`{param}` metadata field",
                            f"`{q.path}`" if q is not None else "missing"))

    gps = [q.path for q in fv.questions.values() if q.bind_type == "geopoint"]
    photo = [q.path for q in fv.questions.values()
             if q.control == "upload" or q.bind_type == "binary"]
    checks.append(Check("INFO", "GPS / register photo (optional)",
                        f"GPS: {', '.join(gps) or 'none'}; photo: {', '.join(photo) or 'none'}"))
    if fv.repeats:
        checks.append(Check("INFO", "Repeat groups",
                            f"{', '.join(fv.repeats)} — OData returns these as separate entity "
                            f"sets; the pull needs `$expand=*`"))
    return checks, scored


def draft_map(fv: FormVersion, scored: list[tuple[Question, tuple]]) -> dict:
    confirm: list[str] = []
    notes: list[str] = []
    entry: dict = {}
    for name in LOGICAL_FIELDS:
        q = find_logical(fv, name)
        entry[name] = q.path if q else None
        confirm.append(name)
        if q is None:
            notes.append(f"{name}: no question found by name")
    for name, param in (("started_at", "start"), ("ended_at", "end")):
        q = find_preload(fv, param)
        entry[name] = q.path if q else None
        if q is None or not q.preload:
            confirm.append(name)

    contract = [(q, ds) for q, ds in scored if ds[3]]
    heuristic = [(q, ds) for q, ds in scored if not ds[3]]
    entry["scored_prefix_pattern"] = f"/{CONTRACT_PATTERN}/" if contract else None
    # Every scored question the form defines (JSON only): the required-item list for
    # rule_preview.py, since an unanswered question may be absent from a submission.
    entry["scored_paths"] = sorted(q.path for q, _ in scored)
    entry["scored_items"] = {q.path: [d, s, code] for q, (d, s, code, _) in heuristic}
    if heuristic:
        confirm.append("scored_items")
        notes.append(f"{len(heuristic)} scored questions matched by name only")

    values = sorted({c for q, _ in scored for c in q.choices})
    if values and set(values) <= set(CONTRACT_CHOICES):
        entry["choices"] = dict(CONTRACT_CHOICES)
    else:
        entry["choices"] = None
        confirm.append("choices")
        notes.append("choice values " + (", ".join(values) if values else "unknown")
                     + " need a pass/fail/na mapping")
    entry["choice_values_seen"] = values
    entry["confirm"] = confirm
    entry["notes"] = notes
    return entry


def diff_versions(a: FormVersion, b: FormVersion) -> dict:
    added = sorted(set(b.questions) - set(a.questions))
    removed = sorted(set(a.questions) - set(b.questions))
    changed = sorted(p for p in set(a.questions) & set(b.questions)
                     if a.questions[p].signature() != b.questions[p].signature())
    # A suggestion only (flagged CONFIRM): same kind of question, similar name, and a clear
    # winner over the next-best candidate.
    renames = []
    for old in removed:
        scored = sorted(((fuzz.ratio(a.questions[old].leaf, b.questions[new].leaf), new)
                         for new in added if a.questions[old].kind == b.questions[new].kind),
                        reverse=True)
        if scored and scored[0][0] >= 70 and (len(scored) == 1 or scored[0][0] - scored[1][0] >= 5):
            renames.append((old, scored[0][1], scored[0][0]))
    return {"added": added, "removed": removed, "changed": changed, "renames": renames}


# ------------------------------------------------------------------------------ output


def php_value(v: object) -> str:
    if v is None:
        return "null"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, int):
        return str(v)
    if isinstance(v, str):
        return "'" + v.replace("\\", "\\\\").replace("'", "\\'") + "'"
    if isinstance(v, list):
        return "[" + ", ".join(php_value(x) for x in v) + "]"
    if isinstance(v, dict):
        return "[" + ", ".join(f"{php_value(k)} => {php_value(x)}" for k, x in v.items()) + "]"
    raise TypeError(type(v))


PHP_KEYS = ["facility_code", "lga_code", "ward_code", "round_year", "round_quarter",
            "started_at", "ended_at", "assessor", "scored_prefix_pattern", "choices"]


def build_php(maps: dict[str, dict], default: str, renames: dict[str, list]) -> str:
    out = ["<?php", "",
           "// DRAFT generated by discovery/field_diff.py — not loaded by the app.",
           "// Lines marked CONFIRM were inferred from question names and must be checked",
           "// against the real form before this moves to config/edqa_field_maps.php.", "",
           "return [", f"    'default' => {php_value(default)},", "    'versions' => ["]
    base = maps[default]
    for version, entry in maps.items():
        additive = version != default
        out.append(f"        {php_value(version)} => ["
                   + (" // additive: only what differs from the default" if additive else ""))
        for key in PHP_KEYS:
            if additive and entry.get(key) == base.get(key):
                continue
            flag = "  // CONFIRM" if key in entry["confirm"] else ""
            out.append(f"            {php_value(key)} => {php_value(entry.get(key))},{flag}")
        if entry["scored_items"]:
            out.append("            // Not in the §5.5 schema: explicit item map for a version whose")
            out.append("            // scored names do not follow the contract.  CONFIRM")
            out.append(f"            'scored_items' => {php_value(entry['scored_items'])},")
        if renames.get(version):
            out.append(f"            'renames' => {php_value(dict((o, n) for o, n in renames[version]))},"
                       "  // CONFIRM (name similarity)")
        out.append("        ],")
    out += ["    ],", "];", ""]
    return "\n".join(out)


def effort(entry: dict, checks: list[Check], is_default: bool) -> str:
    if any(c.requirement.startswith("Scored questions are select_one") and "typed numbers"
           in c.detail for c in checks):
        return "High: scores were typed, not answered per item — cannot be recomputed"
    if entry["scored_items"] or entry["choices"] is None:
        return "Medium: explicit item or choice mapping needed"
    if is_default:
        return "None (default map)"
    return "Low: additive map"


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    p.add_argument("--forms", type=Path, default=HERE / "data" / "forms")
    args = p.parse_args()

    published: dict[str, str] = {}
    index = OUT_DIR / "form_versions.json"
    if index.exists():
        for v in json.loads(index.read_text(encoding="utf-8"))["versions"]:
            published[v["version"]] = v.get("publishedAt") or ""
    files = sorted(args.forms.glob("*.xml"))
    if not files:
        sys.exit(f"No XForms in {args.forms}; run form_versions.py first")

    versions = [parse_xform(f) for f in files]
    for fv in versions:
        fv.published = published.get(fv.version, "")
    versions.sort(key=lambda fv: (fv.published or "", fv.version))
    default = versions[-1].version

    maps: dict[str, dict] = {}
    all_checks: dict[str, list[Check]] = {}
    for fv in versions:
        checks, scored = contract_checks(fv)
        all_checks[fv.version] = checks
        maps[fv.version] = draft_map(fv, scored)

    diffs = [(a, b, diff_versions(a, b)) for a, b in zip(versions, versions[1:])]
    renames: dict[str, list] = {}
    current = versions[-1]
    for fv in versions[:-1]:
        d = diff_versions(fv, current)
        renames[fv.version] = [(o.rsplit("/", 1)[-1], n.rsplit("/", 1)[-1])
                               for o, n, _ in d["renames"]]

    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "field_map.draft.json").write_text(json.dumps(
        {"generated": datetime.now().isoformat(timespec="seconds"), "default": default,
         "versions": maps}, indent=2), encoding="utf-8")
    (OUT_DIR / "edqa_field_maps.draft.php").write_text(build_php(maps, default, renames),
                                                        encoding="utf-8")

    all_paths = set().union(*(fv.questions for fv in versions))
    lines = [
        "# Field diff and form contract",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/field_diff.py` from "
        f"{len(versions)} XForm(s).",
        "",
        "## Versions",
        "",
        "| Version | Published | Questions | Shared with current | Contract | Phase 2/7 effort |",
        "|---|---|---|---|---|---|",
    ]
    for fv in versions:
        checks = all_checks[fv.version]
        shared = len(set(fv.questions) & set(current.questions))
        fails = sum(c.status == "FAIL" for c in checks)
        lines.append(
            f"| `{fv.version or '(blank)'}` | {fv.published[:10]} | {len(fv.questions)} | "
            f"{shared} ({shared / max(len(fv.questions), 1):.0%}) | "
            f"{'meets it' if not fails else f'{fails} FAIL'} | "
            f"{effort(maps[fv.version], checks, fv.version == default)} |")
    lines += ["", f"{len(all_paths)} distinct question paths across all versions.", ""]

    lines += ["## Form contract (ARCHITECTURE.md §5.1)", ""]
    for fv in reversed(versions):
        lines += [f"### Version `{fv.version or '(blank)'}`", "",
                  "| Result | Requirement | Detail |", "|---|---|---|"]
        lines += [f"| **{c.status}** | {c.requirement} | {c.detail.replace('|', '/')} |"
                  for c in all_checks[fv.version]]
        if maps[fv.version]["notes"]:
            lines += ["", "Map notes: " + "; ".join(maps[fv.version]["notes"]) + "."]
        lines.append("")

    lines += ["## Drift between consecutive versions", ""]
    if not diffs:
        lines.append("Only one version: no drift.")
    for a, b, d in diffs:
        lines += [f"### `{a.version or '(blank)'}` → `{b.version or '(blank)'}`", "",
                  f"{len(d['added'])} added, {len(d['removed'])} removed, "
                  f"{len(d['changed'])} changed type or choices, "
                  f"{len(d['renames'])} likely renames.", ""]
        if d["renames"]:
            lines += ["| Old | New | Similarity |", "|---|---|---|"]
            lines += [f"| `{o}` | `{n}` | {s:.0f} |" for o, n, s in d["renames"]]
            lines.append("")
        for label, paths in (("Added", d["added"]), ("Removed", d["removed"]),
                             ("Changed", d["changed"])):
            if paths:
                shown = ", ".join(f"`{x}`" for x in paths[:40])
                lines.append(f"- **{label}:** {shown}{' …' if len(paths) > 40 else ''}")
        lines.append("")
    lines += [
        "## Draft field map",
        "",
        "`out/edqa_field_maps.draft.php` (and `field_map.draft.json` for rule_preview.py). "
        "Entries marked CONFIRM were inferred from question names.",
    ]
    (OUT_DIR / "field_diff.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    console.print(f"{len(versions)} versions, default `{default}`. Wrote out/field_diff.md, "
                  f"out/edqa_field_maps.draft.php, out/field_map.draft.json")


if __name__ == "__main__":
    main()
