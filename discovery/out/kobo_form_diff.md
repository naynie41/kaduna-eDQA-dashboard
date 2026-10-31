# KoboToolbox form diff and contract check

Generated 2026-10-31 18:00 by `discovery/kobo_form_diff.py` from 7 deployed version(s) in `data/forms/kobo/` (the content Kobo keeps per version, `GET /api/v2/assets/{uid}/versions/{version}/`). Names, types and counts only; no answers.

## Summary

- **The form does not meet the portal's form contract** (ARCHITECTURE.md §5.1): 5 of 8 requirements fail in the current version. The identity fields can be mapped; the scored questions cannot be read by the portal's parser as they are.
- **The questions an assessor answers are the same in all 7 versions** (see the matrix): the paths a map names are the same, so one map can name them all. What changed: the form's own score formulas (v2, v3) and when questions show or must be answered: v3: `required` on 7; v5: `required` on 466; v5: `relevant` on 2618; v7: `required` on 742. Those change which items an assessor answers, so whether one scoring reading fits every version is to CONFIRM.
- XLSForm version in the settings: `2024010101` from v6 on; v1, v2, v3, v4, v5 carry none. Kobo labels each deployment with its own uid, so only the uid tells the versions apart.

## Versions

| | Version uid | Deployed | Questions | Calculations | XLSForm version |
|---|---|---|---|---|---|
| v1 | `vJFgGxS4Taw5EZuBt39iBJ` | 2026-09-25 | 2211 | 6 | `` |
| v2 | `vcfuAHiKcZNtqZDtUi3Mdu` | 2026-09-27 | 2211 | 278 | `` |
| v3 | `vgegCDwmG8zxcqTESJ5emv` | 2026-09-28 | 2211 | 278 | `` |
| v4 | `vN8qMQcp7WqWED5xjRHvUg` | 2026-09-28 | 2211 | 278 | `` |
| v5 | `vEekbX34vXcFRPz6JqrdUE` | 2026-10-07 | 2211 | 278 | `` |
| v6 | `vuqLpSWS4Kd5T2XrCKxV8A` | 2026-10-15 | 2211 | 278 | `2024010101` |
| v7 | `vBnJSJRKjVuT4yDaRhKXye` | 2026-10-22 | 2211 | 278 | `2024010101` |

## Question-path × version matrix

3231 paths in all; 2954 are in every version. By top-level group, the paths present in every version:

| Group | Paths | v1 | v2 | v3 | v4 | v5 | v6 | v7 |
|---|---|---|---|---|---|---|---|---|
| `quarterly_section` | 2629 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `monthly_section` | 270 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `follow_up` | 20 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `services` | 12 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `(top level)` | 11 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `description_facility` | 8 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `details_ass` | 4 | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

The 277 paths not in every version: 272 are the form's own score calculations, 5 are questions or notes. By kind:

| Kind | Paths | v1 | v2 | v3 | v4 | v5 | v6 | v7 |
|---|---|---|---|---|---|---|---|---|
| score calculations | 272 | 0 | 272 | 272 | 272 | 272 | 272 | 272 |
| questions and notes | 5 | 0 | 0 | 5 | 5 | 5 | 5 | 5 |

| Question | Type | v1 | v2 | v3 | v4 | v5 | v6 | v7 |
|---|---|---|---|---|---|---|---|---|
| `dqa_score_availability` | note | – | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| `dqa_score_consistency` | note | – | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| `dqa_score_hdr` | note | – | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| `dqa_score_overall` | note | – | – | ✓ | ✓ | ✓ | ✓ | ✓ |
| `dqa_score_validity` | note | – | – | ✓ | ✓ | ✓ | ✓ | ✓ |

The full matrix, every path × version, is in `out/kobo_form_diff.json` (`matrix`).

## Changes per version

### v1 → v2 (`vJFgGxS4Taw5EZuBt39iBJ` → `vcfuAHiKcZNtqZDtUi3Mdu`)

272 added, 0 removed, 0 changed type or choices, 0 likely renames.

- **Added:** `availability_score_pct`, `consistency_score_pct`, `dqa_a_m_m_clinic_sus_m`, `dqa_a_m_m_clinic_sus_p`, `dqa_a_m_m_daily_ant_m`, `dqa_a_m_m_daily_ant_p`, `dqa_a_m_m_daily_attendance_m`, `dqa_a_m_m_daily_attendance_p`, `dqa_a_m_m_fp_reg_m`, `dqa_a_m_m_fp_reg_p`, `dqa_a_m_m_gmp_reg_avail_m`, `dqa_a_m_m_gmp_reg_avail_p` … and 260 more

### v2 → v3 (`vcfuAHiKcZNtqZDtUi3Mdu` → `vgegCDwmG8zxcqTESJ5emv`)

5 added, 0 removed, 0 changed type or choices, 0 likely renames.

- **Added:** `dqa_score_availability`, `dqa_score_consistency`, `dqa_score_hdr`, `dqa_score_overall`, `dqa_score_validity`
- **appearance changed** on 1: `description_facility/healthfacility`
- **label changed** on 1: `details_ass/email_ass`
- **calculation changed** on 41: `dqa_c_m_m_daily_imm_25_m`, `dqa_c_m_m_daily_imm_25_p`, `dqa_c_m_m_daily_labor_24_m`, `dqa_c_m_m_daily_labor_24_p`, `dqa_c_m_m_daily_out_27_m`, `dqa_c_m_m_daily_out_27_p` … and 35 more
- **required changed** on 7: `monthly_section/m_checklist/m_comm_1`, `monthly_section/m_checklist/m_comm_2`, `monthly_section/m_checklist/m_comm_3`, `monthly_section/m_checklist/m_comm_4`, `monthly_section/m_checklist/m_comm_5`, `monthly_section/m_checklist/m_comm_6` … and 1 more

### v3 → v4 (`vgegCDwmG8zxcqTESJ5emv` → `vN8qMQcp7WqWED5xjRHvUg`)

0 added, 0 removed, 0 changed type or choices, 0 likely renames.

- No difference in questions, formulas, labels or choices (only Kobo's own metadata).

### v4 → v5 (`vN8qMQcp7WqWED5xjRHvUg` → `vEekbX34vXcFRPz6JqrdUE`)

0 added, 0 removed, 0 changed type or choices, 0 likely renames.

- **required changed** on 466: `agreement`, `availability_score_pct`, `consistency_score_pct`, `deviceid`, `dqa_a_m_m_clinic_sus_m`, `dqa_a_m_m_clinic_sus_p` … and 460 more
- **label changed** on 4: `details_ass/name_ass`, `deviceid`, `end`, `start`
- **relevant changed** on 2618: `quarterly_section/q_anc_pnc/q_apr_41`, `quarterly_section/q_anc_pnc/q_apr_42`, `quarterly_section/q_anc_pnc/q_apr_43`, `quarterly_section/q_anc_pnc/q_apr_44`, `quarterly_section/q_anc_pnc/q_apr_45`, `quarterly_section/q_anc_pnc/q_apr_46` … and 2612 more
- **Choice list `list_name`:** 0 added, 1 removed (removed: `name`)

### v5 → v6 (`vEekbX34vXcFRPz6JqrdUE` → `vuqLpSWS4Kd5T2XrCKxV8A`)

0 added, 0 removed, 0 changed type or choices, 0 likely renames.

- **label changed** on 4: `dqa_score_availability`, `dqa_score_consistency`, `dqa_score_overall`, `dqa_score_validity`
- **Settings `version`:** `` → `2024010101`
- **Settings `id_string`:** `` → `eDQA_Checklist_updated_scored`
- **Settings `name`:** `` → `eDQA_Checklist_updated_scored`

### v6 → v7 (`vuqLpSWS4Kd5T2XrCKxV8A` → `vBnJSJRKjVuT4yDaRhKXye`)

0 added, 0 removed, 0 changed type or choices, 0 likely renames.

- **required changed** on 742: `dqa_score_availability`, `dqa_score_consistency`, `dqa_score_hdr`, `dqa_score_overall`, `dqa_score_validity`, `monthly_section/m_anc_pnc/m_jan_41` … and 736 more

## How the form scores itself

The form computes its own scores in `calculate` fields named `dqa_<a|c|v>_<m|q>_…` (availability, consistency, validity; monthly or quarterly). The portal never takes a score from a submission (CLAUDE.md hard rule 2), but these formulas show which questions are the scored items and how the form's authors meant them to count:

| Dimension / mode | Items | Item type | How the form scores an item |
|---|---|---|---|
| availability/monthly | 45 | select_one:yes_no (45) | `yes` = 2 points of 2; the item is left out when the service is not offered (`services/*`) |
| availability/quarterly | 496 | select_one:yes_no (496) | `yes` = 2 points of 2; the item is left out when the service is not offered (`services/*`) |
| consistency/monthly | 14 | integer (14) | the answer (0–3 of three clients correct) is the points, of 3; unanswered = 1.5 of 1.5 |
| consistency/quarterly | 168 | integer (168) | the answer (0–3 of three clients correct) is the points, of 3; unanswered = 1.5 of 1.5 |
| validity/monthly | 110 numbers (≈ 36 three-way comparisons) | integer (110) | DHIS = MSF = register (all three entered and equal) = 10 points, else 0 |
| validity/quarterly | 1285 numbers (≈ 428 three-way comparisons) | integer (1285) | DHIS = MSF = register (all three entered and equal) = 10 points, else 0 |

Quarterly items carry their month in the note before them ("January", "February" …); 1949 of 1949 quarterly items have one, which gives the portal's month slot (January, April, July, October = slot 1). Monthly items cover the one month chosen in `review_month`.

## Form contract (ARCHITECTURE.md §5.1)

### v7 `vBnJSJRKjVuT4yDaRhKXye`

| Result | Requirement | Detail | Offending paths |
|---|---|---|---|
| **FAIL** | Facility chosen by cascading select LGA → ward → facility, from a CSV media file | `description_facility/healthfacility` is select_one from the form's own choice list `healthfacility` (1070 choices), filtered by `ward_healthfacility=${ward}`. It cascades, but from choices inside the form, not a CSV file the portal generates, so the portal's master list cannot keep it current | 3: `description_facility/healthfacility`, `description_facility/lga`, `description_facility/ward` |
| **FAIL** | Scored question names match `^(avail|consist|valid)_m[1-3]_[a-z0-9_]+$` | 2118 questions feed the form's own scores; 2118 do not follow the pattern | 2118: `monthly_section/m_anc_pnc/m_dhis_21`, `monthly_section/m_anc_pnc/m_dhis_310`, `monthly_section/m_anc_pnc/m_dhis_41`, `monthly_section/m_anc_pnc/m_dhis_51`, `monthly_section/m_anc_pnc/m_dhis_61`, `monthly_section/m_anc_pnc/m_dhis_71`, `monthly_section/m_anc_pnc/m_dhis_81`, `monthly_section/m_anc_pnc/m_msf_21` … and 2110 more |
| **FAIL** | Scored questions are `select_one` yes / no / na | 1577 are integer; 541 are select_one:yes_no (yes/no) | 2118: `monthly_section/m_anc_pnc/m_dhis_21`, `monthly_section/m_anc_pnc/m_dhis_310`, `monthly_section/m_anc_pnc/m_dhis_41`, `monthly_section/m_anc_pnc/m_dhis_51`, `monthly_section/m_anc_pnc/m_dhis_61`, `monthly_section/m_anc_pnc/m_dhis_71`, `monthly_section/m_anc_pnc/m_dhis_81`, `monthly_section/m_anc_pnc/m_msf_21` … and 2110 more |
| **FAIL** | `round_year` is a read-only calculated field | `description_facility/review_year` is select_one (year_review): chosen by the assessor | 1: `description_facility/review_year` |
| **FAIL** | `round_quarter` is a read-only calculated field | `review_quarter` is select_one (review_quarter): chosen by the assessor | 1: `review_quarter` |
| **PASS** | `meta/instanceID` present | added by Kobo to every deployed form (rows carry `meta/instanceID`) |  |
| **PASS** | `start` metadata field | `start` |  |
| **PASS** | `end` metadata field | `end` |  |
| **INFO** | GPS and register photo optional | GPS: details_ass/location; photo: none |  |

### v6 `vuqLpSWS4Kd5T2XrCKxV8A`: the same as v7

### v5 `vEekbX34vXcFRPz6JqrdUE`: the same as v6

### v4 `vN8qMQcp7WqWED5xjRHvUg`: the same as v5

### v3 `vgegCDwmG8zxcqTESJ5emv`: the same as v4

### v2 `vcfuAHiKcZNtqZDtUi3Mdu`: the same as v3

### v1 `vJFgGxS4Taw5EZuBt39iBJ`: the same as v2

## Is this the same XLSForm as the ODK contract? (Q-16)

No ODK Central form has been downloaded yet (`data/forms/*.xml` is empty: ODK credentials have not arrived), so the comparison is with the ODK contract the portal was built to (`config/edqa_field_maps.php`, map `xls-2026.1`):

| Field | ODK contract path | This Kobo form |
|---|---|---|
| facility_code | `facility.facility_code` | `description_facility/healthfacility` |
| lga_code | `facility.lga` | `description_facility/lga` |
| ward_code | `facility.ward` | `description_facility/ward` |
| round_year | `round_year` | `description_facility/review_year` |
| round_quarter | `round_quarter` | `review_quarter` |
| started_at | `start` | `start` |
| ended_at | `end` | `end` |
| assessor | `assessor_name` | `details_ass/name_ass` |
| gps | `gps` | `details_ass/location` |
| scored items | `/^(avail|consist|valid)_m([1-3])_([a-z0-9_]+)$/`, yes/no/na | none follow it: 2118 items in three shapes (yes/no; a 0–3 count; three numbers to compare) |

**No.** It is a different form from the one the contract describes: other group and question names, the review period chosen by the assessor, and items that are not yes/no/na. If the client also collects on ODK Central, ask whether it is this same XLSForm there (Q-16).

## Questions for the client

1. **Which form is the one the portal reports on?** This asset has 6 submissions (2026-Q4: 6) and 7 deployments between 2026-09-25 and 2026-10-22. Is it a pilot of the real form, and is it the form deployed on ODK Central too (Q-16)?
2. **Adapt the form, or the portal?** The contract (§5.1) asks for yes/no/na items named `(avail|consist|valid)_m[1-3]_…`. Adapting the XLSForm keeps the portal's scoring as built; reading this form as it is means new parser rules and decisions below. Which does the client want?
3. **Availability:** is a `no` a fail and a `yes` a pass, with an item that has no answer because the service is not offered counted as not applicable (not a fail)?
4. **Consistency:** each item is a count of three clients correct (0–3). How should 0, 1, 2 and 3 count? The form gives the count as points out of 3 (so 2 of 3 is two-thirds of a pass); the portal counts whole items. And an unanswered consistency item scores 1.5 of 1.5 in the form, a full pass: is that intended?
5. **Validity:** a month passes in the form only when DHIS, MSF and the register agree exactly (10 points, else 0). Is exact equality the rule, or is a tolerance allowed? Is an item with one of the three numbers missing a fail or not applicable?
6. **Weights:** the form weights items differently (2, 3 and 10 points). The portal's published scoring is an unweighted mean of items passed per dimension and month. Which is the published method?
7. **Monthly and quarterly DQA in one form:** which visits does the portal count, quarterly only, or monthly too? A monthly visit covers one month of the quarter: does it make a round's assessment on its own?
8. **The round:** the assessor chooses `review_year`, `review_quarter` (or `review_month`). Can these become calculated, read-only fields (the contract), or is the assessor's choice the round? What if it disagrees with the visit date?
9. **Facilities:** the facility is chosen from the form's own list `healthfacility` (1070 choices) with names like `abcdef_ghijk_lmnop_1`, not the master list's codes. Can the form use the portal's CSV media file (LGA → ward → facility, the portal's codes)? If not, who maintains the mapping from these names to facility codes?
10. **Action plans:** the form records follow-up action plans per dimension (`follow_up/*`: plan, responsible person, due date). Should the portal read them into its implementation plan, or is the portal's plan (generated from the scores) the one that counts?
11. **`field_visit_type`, `ddu_dqa`:** community visits and DDU at LGA level are in the same form. Are those excluded from facility scores?

