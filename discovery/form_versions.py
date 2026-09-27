"""List every published version of the DQA form and download each version's XForm and XLSForm.

Answers Q-05 (how many versions). The downloads feed field_diff.py.

Writes data/forms/<version>.xml (and .xlsx when Central has one), out/form_versions.json and
out/form_versions.md. Usage: python form_versions.py
"""

from __future__ import annotations

import json
import re
from datetime import datetime

from rich.console import Console

from odk import DATA_DIR, OUT_DIR, OdkClient, load_config, run, version_segment

console = Console()
FORMS_DIR = DATA_DIR / "forms"


def safe_name(version: str) -> str:
    return re.sub(r"[^A-Za-z0-9._-]", "_", version) or "_blank"


def main() -> None:
    cfg = load_config()
    FORMS_DIR.mkdir(parents=True, exist_ok=True)
    with OdkClient(cfg) as odk:
        form = odk.get_json(odk.form_path, headers={"X-Extended-Metadata": "true"})
        versions = odk.get_json(f"{odk.form_path}/versions")
        versions.sort(key=lambda v: v.get("publishedAt") or "")

        records = []
        for v in versions:
            version = v.get("version") or ""
            base = f"{odk.form_path}/versions/{version_segment(version)}"
            record = {"version": version, "publishedAt": v.get("publishedAt"),
                      "xml": None, "xlsx": None}
            xml = odk.get(f"{base}.xml", throttle=True)
            if xml.status_code == 200:
                path = FORMS_DIR / f"{safe_name(version)}.xml"
                path.write_bytes(xml.content)
                record["xml"] = str(path.relative_to(DATA_DIR.parent)).replace("\\", "/")
            xlsx = odk.get(f"{base}.xlsx", throttle=True)
            if xlsx.status_code == 200:
                path = FORMS_DIR / f"{safe_name(version)}.xlsx"
                path.write_bytes(xlsx.content)
                record["xlsx"] = str(path.relative_to(DATA_DIR.parent)).replace("\\", "/")
            records.append(record)
            console.print(f"{version or '(blank)':<24} {(v.get('publishedAt') or '')[:10]}  "
                          f"xml={'yes' if record['xml'] else 'NO'}  "
                          f"xlsx={'yes' if record['xlsx'] else 'no'}")

    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "form_versions.json").write_text(json.dumps(
        {"form_id": cfg.form_id, "current": form.get("version") or "", "versions": records},
        indent=2), encoding="utf-8")

    lines = [
        "# Form versions",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/form_versions.py`.",
        "",
        f"Form `{cfg.form_id}` ({form.get('name') or 'unnamed'}): **{len(records)} published "
        f"version(s)**; current version `{form.get('version') or '(blank)'}`; "
        f"{form.get('submissions', '?')} submissions in total.",
        "",
        "| # | Version | Published | XForm | XLSForm |",
        "|---|---|---|---|---|",
    ]
    for i, r in enumerate(records, start=1):
        lines.append(f"| {i} | `{r['version'] or '(blank)'}` | {(r['publishedAt'] or '')[:10]} | "
                     f"{'downloaded' if r['xml'] else '**missing**'} | "
                     f"{'downloaded' if r['xlsx'] else 'not available (uploaded as XML)'} |")
    lines += ["", "Next: `python field_diff.py` compares the question names across these versions."]
    (OUT_DIR / "form_versions.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    console.print(f"{len(records)} versions. Wrote out/form_versions.md")


if __name__ == "__main__":
    run(main)
