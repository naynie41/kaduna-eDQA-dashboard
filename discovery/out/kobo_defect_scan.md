# KoboToolbox defect scan

Generated 2026-10-31 23:06 by `discovery/defect_scan.php`: 6 submission(s) from `data/submissions/kobo/`, through the portal's own normaliser, parser (the real field map and item rules) and all 14 validation rules, read-only. Reference data from the `edqa` database (**local: a synthetic master list and demo rounds**, so FACILITY_UNKNOWN and ROUND_WINDOW below reflect that, not the real data).

## Outcome

| | Submissions |
|---|---|
| Scanned | 6 |
| Would be accepted | 0 |
| Would be accepted with a flag | 0 |
| Would be quarantined | 6 |
| Not a facility assessment (out of scope, D-74) | 0 |

## Failures by rule and year (the reviewed year)

| Rule | Severity | 2026 | 2027 | 2029 | Total |
|---|---|---|---|---|---|
| `FACILITY_UNKNOWN` | hard | 4 | 1 | 1 | 6 |
| `ROUND_WINDOW` | hard | 4 | 1 | 1 | 6 |
| `VISIT_TOO_SHORT` | soft | 3 | 0 | 0 | 3 |

## Failures by rule and form version

| Rule | `vBnJSJRK…` |
|---|---|
| `FACILITY_UNKNOWN` | 6 |
| `ROUND_WINDOW` | 6 |
| `VISIT_TOO_SHORT` | 3 |

## Examples (up to 3 per rule; instance IDs shortened, assessor names removed)

**`FACILITY_UNKNOWN`**
- `uuid:…d430: facility 'chikun_kakau_45' is not on the master list`
- `uuid:…29a0: facility 'ikara_ikara_197' is not on the master list`
- `uuid:…6c08: facility 'zangon_kataf_zonkwa_1007' is not on the master list`

**`ROUND_WINDOW`**
- `uuid:…d430: visit date 2026-10-22 is not inside any round's window`
- `uuid:…29a0: visit date 2026-10-24 is not inside any round's window`
- `uuid:…6c08: visit date 2026-10-25 is not inside any round's window`

**`VISIT_TOO_SHORT`**
- `uuid:…d430: visit lasted 11 minutes (minimum 20)`
- `uuid:…29a0: visit lasted 18 minutes (minimum 20)`
- `uuid:…5dee: visit lasted 16 minutes (minimum 20)`

## LGAs and facilities

| LGA value submitted | Submissions | One of the 23? |
|---|---|---|
| `chikun` | 1 | yes: Chikun |
| `ikara` | 2 | yes: Ikara |
| `makarfi` | 1 | yes: Makarfi |
| `sabon_gari` | 1 | yes: Sabon Gari |
| `zangon_kataf` | 1 | yes: Zangon Kataf |

- A blank LGA: none. A value that is not one of the 23 (a "24th LGA"): none.
- Distinct facility references: 6 (written to `data/kobo_facilities.csv` for `facility_reconcile.py`).

## Edits and duplicates

- Edits (`meta/deprecatedID` set): 0.
- Instance IDs that appear more than once: none.
- Kobo `_id`s with more than one version (an edit chain): none.
- Visits to the same facility for the same reviewed period and month slot (would be DUPLICATE_ASSESSMENT): none.

## What the field map could not read

| | Submissions |
|---|---|
| form version with no field map | 0 |
| visit mode not read (period_visit) | 0 |
| reviewed month or quarter not read | 0 |
| no start time | 0 |
| no end time | 0 |
| no LGA | 0 |
| no ward | 0 |
| no facility | 0 |
| no assessor | 0 |

- No item answer outside what its rule reads (no `invalid` item).
- Share of items not applicable per submission: from 0% to 42% (services not offered, or questions left unanswered).
