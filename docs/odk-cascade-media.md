# Uploading the facility cascade to ODK Central

The ODK form picks the facility with a cascading select: LGA, then ward, then facility. The
lists come from three CSV media files that the portal generates from its master facility list
(ARCHITECTURE.md §5.1, D-54). Until the portal has ODK credentials with permission to publish
form drafts, **an ODK project manager uploads them by hand**. The portal itself only ever reads
from ODK (SECURITY.md §6).

Do this whenever facilities are added, renamed, moved or deactivated, and before each round.

## 1. Generate the files

As an administrator, call `POST /api/facility-list/export-cascade`. The Facility list page will
have a button for this once it is built (Phase 5). The answer gives the number of LGAs, wards
and facilities, and a download link valid for 30 minutes. The download is a zip,
`odk-cascade-YYYYMMDD-HHMMSS.zip`, holding:

| File | Columns | Contains |
|---|---|---|
| `lgas.csv` | `name, label` | LGAs with at least one active facility |
| `wards.csv` | `name, label, lga` | Wards with at least one active facility |
| `facilities.csv` | `name, label, lga, ward` | Active facilities only |

`name` is the code the portal reads back (LGA code, ward code, facility code); `label` is what
the assessor sees. Inactive facilities are left out, so they cannot be picked.

Generation and every download are recorded in the audit log.

## 2. Check them

Open `facilities.csv` and check that the count matches the Facility list's active facilities, and
that a facility you know is under the right LGA and ward. A label that begins with `=`, `+`,
`-` or `@` is written with a leading `'` so a spreadsheet never runs it as a formula.

## 3. Upload to ODK Central

1. In ODK Central, open the project, then the DQA form.
2. Create a new draft (**Edit Form** or **Create a new Draft**). If the form definition is
   unchanged, upload the same XLSForm again.
3. Under **Form Attachments**, upload `lgas.csv`, `wards.csv` and `facilities.csv`. The names
   must stay exactly as they are; the form refers to them by name.
4. Test the draft on a device: pick an LGA, check that the ward list is only that LGA's wards,
   pick a ward, check its facilities.
5. **Publish** the draft with a new version string (for example `2026.2`).

## 4. Tell the portal about the new version

A new form version that the portal has no field map for is quarantined under
`UNKNOWN_FORM_VERSION`, never guessed. Before publishing, add the version to
`config/edqa_field_maps.php`. If only the media changed, an additive entry with no differences is
enough. Then run `php artisan edqa:odk:check` to confirm the portal lists it as mapped.

## In the XLSForm

For reference, the three questions read the files like this:

| type | name | choice_filter |
|---|---|---|
| `select_one_from_file lgas.csv` | `lga` | |
| `select_one_from_file wards.csv` | `ward` | `lga = ${lga}` |
| `select_one_from_file facilities.csv` | `facility_code` | `ward = ${ward}` |

(in the `facility` group, matching the field map's `facility.lga`, `facility.ward` and
`facility.facility_code`; CONTRACT-ASSUMED until the real form is audited).
