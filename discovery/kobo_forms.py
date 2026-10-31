"""Export every deployed KoboToolbox version as an XLSForm, for field_diff.py.

Kobo downloads an XLSForm of the current version only, but it keeps each version's content (the
survey, choices and settings rows). This rebuilds each deployed version's XLSForm from that
content, so field_diff.py can check every version against the form contract and diff them, as
it does for ODK Central's XForms.

Reads out/kobo_form_versions.json (kobo_inventory.py) for the deployed versions, or lists them
itself. Writes data/kobo/forms/<version uid>.xlsx (git-ignored) and out/kobo_forms.md. Each
workbook's settings carry the version uid as `version`, which is what every Kobo submission
names in `__version__` and what `sources.kobo` in config/edqa_field_maps.php is keyed by.

Usage:
  python kobo_forms.py
  python kobo_forms.py --offline fixtures/kobo
Then:
  python field_diff.py --source kobo
"""

from __future__ import annotations

import argparse
from datetime import datetime

from openpyxl import Workbook
from rich.console import Console

from kobo import DATA_DIR, OUT_DIR, add_source_args, deployed_versions, open_source, run

console = Console()
FORMS_DIR = DATA_DIR / "forms"
SURVEY_COLUMNS = ["type", "name", "label", "required", "relevant", "calculation", "choice_filter",
                  "parameters", "read_only", "appearance"]
SELECTS = ("select_one", "select_multiple")


def text(value: object) -> str:
    """A cell: Kobo stores labels as a list (one per language); the first is enough here."""
    if isinstance(value, list):
        return str(value[0]) if value else ""
    if isinstance(value, bool):
        return "true" if value else ""
    return "" if value is None else str(value)


def type_cell(row: dict) -> str:
    """The XLSForm type: Kobo splits `select_one yesno` into type + select_from_list_name."""
    kind = str(row.get("type", ""))
    if kind in SELECTS and row.get("select_from_list_name"):
        return f"{kind} {row['select_from_list_name']}"
    if kind in ("select_one_from_file", "select_multiple_from_file"):
        file = row.get("file") or row.get("select_from_list_name") or ""
        return f"{kind} {file}".strip()
    return kind


def workbook(content: dict, uid: str, title: str, asset_uid: str) -> Workbook:
    wb = Workbook()
    survey = wb.active
    survey.title = "survey"
    survey.append(SURVEY_COLUMNS)
    for row in content.get("survey", []):
        values = {**row, "type": type_cell(row), "name": row.get("name") or row.get("$autoname", "")}
        survey.append([text(values.get(c)) if c != "type" else values["type"] for c in SURVEY_COLUMNS])
    choices = wb.create_sheet("choices")
    choices.append(["list_name", "name", "label"])
    for row in content.get("choices", []):
        choices.append([text(row.get("list_name")), text(row.get("name") or row.get("$autovalue")),
                        text(row.get("label"))])
    settings = wb.create_sheet("settings")
    settings.append(["form_title", "form_id", "version"])
    stored = content.get("settings") or {}
    if isinstance(stored, list):
        stored = stored[0] if stored else {}
    settings.append([text(stored.get("form_title")) or title, text(stored.get("id_string")) or asset_uid, uid])
    return wb


def main() -> None:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawTextHelpFormatter)
    add_source_args(p)
    args = p.parse_args()

    FORMS_DIR.mkdir(parents=True, exist_ok=True)
    rows: list[tuple[str, str, int, int]] = []
    with open_source(args) as kobo:
        asset = kobo.asset()
        title = str(asset.get("name", ""))
        for v in deployed_versions(asset, kobo.versions()):
            uid = str(v.get("uid"))
            content = kobo.version(uid).get("content") or {}
            workbook(content, uid, title, kobo.asset_uid).save(FORMS_DIR / f"{uid}.xlsx")
            rows.append((uid, str(v.get("date_deployed", ""))[:10], len(content.get("survey", [])),
                         len(content.get("choices", []))))

    lines = [
        "# KoboToolbox form versions",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/kobo_forms.py`"
        + (f" from saved responses in `{args.offline}`." if args.offline else "."),
        "",
        "Each deployed version rebuilt as an XLSForm in `data/kobo/forms/` (not committed). "
        "`field_diff.py --source kobo` reads them.",
        "",
        "| Version uid | Deployed | Survey rows | Choice rows |",
        "|---|---|---|---|",
    ]
    lines += [f"| `{uid}` | {date} | {s} | {c} |" for uid, date, s, c in rows]
    (OUT_DIR / "kobo_forms.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    console.print(f"{len(rows)} version(s) exported to data/kobo/forms/. Wrote out/kobo_forms.md")


if __name__ == "__main__":
    run(main)
