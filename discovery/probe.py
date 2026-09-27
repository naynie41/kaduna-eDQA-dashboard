"""First contact with ODK Central: server version, project, forms, and the account's permissions.

Answers Q-01 (Central, not Aggregate) and checks the service account is read-only
(SECURITY.md §6: Project Viewer, never a site admin).

Writes out/probe.md. Usage: python probe.py
"""

from __future__ import annotations

from datetime import datetime

from rich.console import Console
from rich.table import Table

from odk import OUT_DIR, OdkClient, load_config, run

console = Console()
EXTENDED = {"X-Extended-Metadata": "true"}
WRITE_MARKERS = (".create", ".update", ".delete", ".restore", ".set", ".assign", ".unassign",
                 ".invalidate", ".end", ".run", ".reset")


def main() -> None:
    cfg = load_config(require_form=False)
    with OdkClient(cfg) as odk:
        version_response = odk.get("/version.txt", auth=False)
        central_version = (version_response.text.strip()
                           if version_response.status_code == 200 else "")
        project = odk.get_json(odk.project_path, headers=EXTENDED)
        forms = odk.get_json(f"{odk.project_path}/forms", headers=EXTENDED)

    verbs = sorted(project.get("verbs") or [])
    write_verbs = [v for v in verbs if v.endswith(WRITE_MARKERS)]
    forms = sorted(forms, key=lambda f: f.get("xmlFormId", ""))

    table = Table(title=f"Project {project.get('id')}: {project.get('name')}")
    for col in ("xmlFormId", "Name", "Version", "State", "Submissions", "Last submission"):
        table.add_column(col)
    for f in forms:
        table.add_row(f.get("xmlFormId", ""), f.get("name") or "", f.get("version") or "(blank)",
                      f.get("state", ""), str(f.get("submissions", "?")),
                      (f.get("lastSubmission") or "")[:10])
    console.print(f"ODK Central version: {central_version or 'not reported'}")
    console.print(table)
    if write_verbs:
        console.print(f"[yellow]The account can write in this project ({len(write_verbs)} write "
                      f"permissions). SECURITY.md §6 requires a Project Viewer account.[/]")

    configured = next((f for f in forms if f.get("xmlFormId") == cfg.form_id), None)
    lines = [
        "# ODK Central probe",
        "",
        f"Generated {datetime.now():%Y-%m-%d %H:%M} by `discovery/probe.py`.",
        "",
        "| | |",
        "|---|---|",
        f"| Server | {cfg.url} |",
        f"| ODK Central version | {' '.join(central_version.split()) or 'not reported at /version.txt'} |",
        f"| Project | {project.get('id')}: {project.get('name')} |",
        f"| Forms in project | {len(forms)} |",
        f"| Configured form (`ODK_FORM_ID`) | "
        f"{'`' + cfg.form_id + '` found' if configured else ('`' + cfg.form_id + '` NOT FOUND' if cfg.form_id else 'not set')} |",
        f"| Account permissions in project | {len(verbs)} total, {len(write_verbs)} write |",
        "",
        "## Forms",
        "",
        "| xmlFormId | Name | Current version | State | Submissions | Created | Last submission |",
        "|---|---|---|---|---|---|---|",
    ]
    for f in forms:
        lines.append(
            f"| `{f.get('xmlFormId', '')}` | {f.get('name') or ''} | {f.get('version') or '(blank)'} "
            f"| {f.get('state', '')} | {f.get('submissions', '?')} | {(f.get('createdAt') or '')[:10]} "
            f"| {(f.get('lastSubmission') or '')[:10]} |")
    lines += [
        "",
        "## Findings",
        "",
        f"- **Q-01 (Central or Aggregate):** "
        + ("ODK Central; the `/v1` API and OData feed are available."
           if central_version or forms else "the `/v1` API answered, but no version was reported."),
        "- **Service account:** "
        + ("read-only in this project, as SECURITY.md §6 requires."
           if not write_verbs else
           f"**can write** ({', '.join(f'`{v}`' for v in write_verbs[:12])}"
           f"{' …' if len(write_verbs) > 12 else ''}). Ask the client for a Project Viewer "
           f"account before Phase 2."),
    ]
    OUT_DIR.mkdir(exist_ok=True)
    (OUT_DIR / "probe.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    console.print("Wrote out/probe.md")


if __name__ == "__main__":
    run(main)
