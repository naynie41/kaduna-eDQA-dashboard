# KoboToolbox findings: Kaduna eDQA Portal

| | |
|---|---|
| Prepared for | Kaduna State eDQA programme team and HSDF |
| Date | 31 October 2026 |
| Based on | A read-only review of the KoboToolbox project "Kaduna State eDQA (NHMIS Data Quality Assurance)", and a trial load of its submissions into a test copy of the portal |
| Status | For discussion. Sections 7 and 8 list what we need from you |

We connected the portal to your KoboToolbox account using the access key you supplied. We only
read; nothing on KoboToolbox was changed, added or deleted. No assessor names appear in this
document.

## 1. Summary

- The KoboToolbox project holds **6 submissions**, all sent between **22 and 25 October 2026**.
  It holds no earlier history, so it cannot fill in the past quarters the old Looker Studio report
  shows.
- The form does not follow the layout the portal was first designed for. Rather than ask you to
  rebuild it, **the portal now reads the form as it is**. On the visits we tested, the portal's
  scores agree with the form's own to the decimal, apart from one deliberate difference
  (section 3).
- **All 6 submissions would currently be held back from the dashboards.** Their facilities can't
  yet be matched to the official facility list, and two of them name review periods in the future
  (section 4).
- **The access key belongs to the account that owns the form**, so it can also edit and delete
  data. Please create a separate view-only account for the portal (section 6).
- Your KoboToolbox data is stored on the **EU server**, outside Nigeria. Please confirm that this
  is acceptable (section 6).

## 2. What is on KoboToolbox

| | |
|---|---|
| Project | Kaduna State eDQA (NHMIS Data Quality Assurance) |
| Server | KoboToolbox EU server (eu.kobotoolbox.org) |
| Status | Deployed and accepting submissions |
| Form versions | 7, published between 25 September and 22 October 2026 |
| Submissions | 6 in total |

**Submissions per year**

| Year submitted | Submissions |
|---|---|
| 2024 | 0 |
| 2025 | 0 |
| 2026 | 6 (all 22–25 October) |

**The review period each submission names** (chosen by the assessor on the form)

| Period reviewed | Visit type | Submissions |
|---|---|---|
| July–September 2026 (Q3) | Monthly visits: July (2), September (2) | 4 |
| January 2027 (Q1 2027) | Monthly visit | 1 |
| Q1 2029 | Quarterly visit | 1 |

The two 2027 and 2029 entries name periods that haven't happened yet. We assume they are test
entries or typing mistakes (question Q-20).

**The 7 versions.** The questions an assessor answers are the same in all seven. Between versions
the form gained its own score calculations, and some questions were made required or made to show
only when relevant. All seven are recognised by the portal, so a submission from any version can
be read.

## 3. Does the form meet the portal's requirements?

The portal was first specified around a simpler form: every scored question a yes / no / not
applicable answer, the facility picked from a list the portal supplies, and the review period
filled in automatically. Your form differs on five points:

| Requirement | Your form | What we have done |
|---|---|---|
| Facility picked from the portal's facility list | Picked from the form's own list of 1,070 facility names | The portal matches each name to an official facility code, using a matching table you sign off (section 5). We recommend the form switch to the portal's list later |
| Scored questions named in a fixed pattern | Different names | The portal now reads your names directly |
| Scored questions answered yes / no / not applicable | Three kinds: yes/no (availability), "how many of 3 clients were correct" (consistency), and three numbers to compare, DHIS2, monthly summary form and register (validity) | The portal reads all three kinds, scoring them the way your form's own formulas do |
| Year of review filled in automatically | Chosen by the assessor | Agreed with you: the form will calculate it (below) |
| Quarter of review filled in automatically | Chosen by the assessor (quarter or month) | As above |

The visit start and end times, the location and the submission identifier are all present and
read correctly.

**How the portal's scores compare with the form's.** We scored one real visit in full and checked
the other five. The portal's availability, consistency, validity and overall scores equal the
form's own to the decimal. There are two known differences, both decided earlier with you:

- **A consistency question left blank.** The form gives it full marks; the portal treats it as
  not applicable, because the portal never gives credit for a question that wasn't answered. A
  facility can therefore score slightly lower in the portal than the form shows.
- **Several months in one dimension.** The form pools all months into one percentage; the portal
  scores each month and averages them. This is the published method the portal was checked
  against. The two differ only when months have different numbers of applicable questions.

**What must change before go-live**

On the form (yours):

1. **Calculated review period.** Add read-only calculated fields for the year and quarter of
   review, worked out from the assessor's choice of year and month or quarter. (Agreed. Until it
   is deployed, the portal works these out itself in the same way.)
2. **Facility list.** Either sign off the matching table in section 5, or switch the LGA, ward and
   facility questions to read from the portal's facility file, so new or renamed facilities stay
   in step with the official list automatically. We recommend the switch (question Q-21).
3. **Remove or correct test entries** before go-live, such as the 2027 and 2029 submissions
   (question Q-20).

On the portal (ours, already planned):

4. Combine several monthly visits to one facility in one quarter into that quarter's single
   assessment. Until this is built, a second monthly visit is held back as a duplicate.
5. Read the action plans recorded on the form into the portal's implementation plan.

Before go-live (both):

6. A view-only KoboToolbox account for the portal (section 6).
7. Agreed round dates (question Q-10).

## 4. The defect picture: what would be held back, and why

Every submission passes 14 checks before it can affect any number. A submission that fails a
serious check is **held back in quarantine** and listed on the portal's Data issues page for an
administrator. It never reaches a chart. A lesser problem is **flagged**: the submission still
counts, with a warning.

In our trial load, using a test round for July–September 2026 that runs to 31 October (see
Q-10), **all 6 submissions were held back**:

| Check | What it means | Serious? | 2026 | 2027 | 2029 | Total |
|---|---|---|---:|---:|---:|---:|
| Facility not on the list (`FACILITY_UNKNOWN`) | The facility could not be matched to the official facility list | Held back | 4 | 1 | 1 | **6** |
| Outside any round (`ROUND_WINDOW`) | The review period named on the form has no round, or the visit date falls outside its round's dates | Held back | 0 | 1 | 1 | **2** |
| Visit too short (`VISIT_TOO_SHORT`) | The form was open less than 20 minutes (11, 16 and 18 minutes here) | Flag only | 3 | 0 | 0 | **3** |

Years are the year of the period reviewed. A submission that fails more than one check is counted
under each.

- **Facility not on the list (6):** expected at this stage. The official facility list and the
  matching table haven't been loaded yet (section 5). Once they are, the 4 July–September visits
  should pass this check.
- **Outside any round (2):** the two entries reviewing January 2027 and Q1 2029. They are correctly
  held back.
- **Visit too short (3):** three visits took under 20 minutes. They would still count, with a
  flag. Please tell us whether 20 minutes is a realistic minimum for this form (question Q-22).

**Other checks, all clear:**
- every LGA named is one of the 23;
- no visit has its end before its start;
- no answer falls outside what its question allows;
- no submission was sent twice;
- no submission was edited after sending;
- no form version is unrecognised.

When we first ran the checks, the four July–September visits also fell outside their round. They
were made in October, after the quarter they review had ended. Round dates therefore need to allow
for this delay (question Q-10).

## 5. Facilities not yet matched to the master list

The form picks facilities from its own list of 1,070 names, not from the official facility codes.
None of the 6 facilities below could be matched, because the official master list hasn't been
delivered yet (question Q-06). Once it arrives we match each name to a facility code and send you
the table for sign-off. The portal never guesses a facility.

| Form's facility name | Facility | Ward | LGA |
|---|---|---|---|
| chikun_kakau_45 | Kakau Daji Primary Health Care Clinic | Kakau | Chikun |
| ikara_ikara_197 | Ikara Primary Health Care Center | Ikara | Ikara |
| ikara_ikara_202 | Zage Zagi Health Clininc (spelled so on the form) | Ikara | Ikara |
| makarfi_gubuchi_790 | Tudun Wada Gubuchi Primary Health Care Clinic | Gubuchi | Makarfi |
| sabon_gari_unguwan_gabas_834 | Unguwan Jaba Primary Health Care Center | Unguwan Gabas | Sabon Gari |
| zangon_kataf_zonkwa_1007 | Zonkwa Primary Health Care Center | Zonkwa | Zangon Kataf |

The form's full list of 1,070 facilities has been extracted, so the whole table can be matched in
one go when the master list arrives.

## 6. Security and data location

**The access key is not view-only.** It belongs to the account that owns the form. Besides viewing,
that account can add, edit, validate and delete submissions, change or delete the form, and manage
who has access. It also owns 4 other KoboToolbox projects. The portal only ever reads, but a key
this powerful should not sit on a server.

**Request (Q-18):** please create a separate KoboToolbox account for the portal, and share only
this project with it, with permission to **view the form and view submissions** and nothing more.
Then send us that account's access key. We will replace the current key and confirm the new one
can't change anything. Please also consider changing the current key once the new one is in place.

**Where the data is stored (Q-17).** The project is on KoboToolbox's EU server, so submissions are
stored in the European Union, outside Nigeria. The portal itself will run on the server agreed in
the hosting decision. Please confirm whether any Nigerian or State data-protection rule requires
health facility data to stay in Nigeria. If it does, KoboToolbox would need to move to a
self-hosted server.

## 7. Open questions that block go-live

| # | Question | Why it matters |
|---|---|---|
| Q-15 | **Why both ODK Central and KoboToolbox?** Are they split by LGA, by period (for example history on one, current rounds on the other), by partner, or do they overlap? If the same facility and quarter appear on both, which copy counts? | Without an answer, a facility visited on both is held back as a duplicate, and we can't say which platform holds the history |
| Q-16 | **Is the same form used on both platforms**, and are new versions published to both together? | We now know the KoboToolbox form. The ODK Central form can't be checked until its access details arrive. If they differ, each needs its own reading |
| Q-10 | **Round dates.** How long after a quarter ends may its visits still be made? | The July–September visits were made in late October. The portal accepts a visit only if its date falls inside its quarter's round dates, so the dates must allow for the delay. Our test used 1 July–31 October. If the delay is always one month, rounds would run August–October, November–January and so on |

## 8. Other questions arising

| # | Question |
|---|---|
| Q-19 | **Where is the history?** The old Looker Studio report shows earlier quarters, but this KoboToolbox project has nothing before 22 October 2026. Did earlier rounds come from ODK Central, another KoboToolbox project (the owning account has 4 others), or somewhere else? |
| Q-20 | **The January 2027 and Q1 2029 submissions:** are they tests or mistakes? Can they be removed or corrected on KoboToolbox? (The portal holds them back either way.) |
| Q-21 | **Facility questions:** will you switch the form's LGA, ward and facility questions to read from the portal's facility file? Until then, someone must keep the matching table up to date whenever the form's facility list changes. Who? |
| Q-22 | **Minimum visit length:** is 20 minutes realistic for this form? Three of the six test visits took 11–18 minutes |
| Q-06 | **Master facility list:** who supplies and signs it off, and by when? Every facility match, and so every score, waits on it |
