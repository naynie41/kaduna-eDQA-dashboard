# Demo script: the eDQA dashboard (Phase 4)

A 10-minute walkthrough for the Kaduna State and HSDF team, using the demo data.
Version 0.4.0, October 2026.

**Before the meeting (5 minutes)**

1. Start the local system: `make up`, then sign in at http://localhost:8080.
2. Hold the old report's broken row in quarantine, for part 5:
   `make artisan c="edqa:demo:quarantine"`.
   It prints the four reasons it was held. It runs once: a second run just says the row is
   already held.
3. Open two browser tabs:
   - **Home** for 2026 Q2: http://localhost:8080/dashboard/home?year=2026&quarter=2
   - the **Data issues API**: http://localhost:8080/api/quarantine?group=rule
4. Make the browser window full width, at 100% zoom.

The figures below are from the demo data, Q2 2026. **Say clearly at the start that the data is
synthetic, modelled on the published scorecards.** The real data arrives when ODK Central is
connected.

---

## 1. The state headline (1½ min)

**Show:** the Home tab, Year 2026, Quarter Q2.

**Say:**

- "This is the quarter in one look. The average quality score across the **439 facilities**
  visited in **all 23 local governments** is **86, Acceptable**."
- "175 facilities score 90 or more. 21 are under 70: those need support."
- "Every number here is worked out by the system from the individual answers in each
  assessment, not typed in. A score that cannot be true never reaches this page. You will see
  what happens to one in a few minutes."

**Point at:** Public vs Private. Public facilities average **87**, private **73, Review**.
"Private facilities are the gap."

**Point at:** Quality score by quarter. "Every round is shown, so this quarter is always seen
against the ones before it. The sentence above the chart says what changed." Click **Show as
table** to show that the same numbers are there as a table for anyone who cannot use the chart.

## 2. The weakest dimension (1 min)

**Show:** "The three dimensions" on Home.

**Say:**

- "Availability, whether the register was there to check, is **89.9**. Validity, whether entries
  meet the definitions, is **90.5, Strong**."
- "**Consistency is the weakest, at 77.5, Review.** The monthly summary forms do not agree with
  the registers often enough."
- "In private facilities consistency is **58.6, Needs action**."

The mark on each bar is the Strong threshold of 90. It comes from the scoring rules, which an
administrator can change and which are versioned. No threshold is fixed in the screens.

## 3. Drilling into a local government (2 min)

**Show:** the **LGA view** tab. The badge says **23**: there can never be a 24th LGA (part 5).

**Say:** "Every local government is a tile, with its score and number of facilities, highest
score first. The table below has all four measures for each."

**Click:** the **Birnin Gwari** tile (77.4, Review). It becomes pressed, and the LGA filter at
the top changes to Birnin Gwari.

**Say:**

- "Birnin Gwari is the weakest local government: **77.4**, from **11 facilities**."
- "Its consistency is **67.9, Needs action**, the lowest of any dimension in any LGA."

**Show:** **Home** again, with Birnin Gwari still selected. "With one local government chosen,
the 'weakest' list shows its weakest **wards**, so the LGA team knows where to go first."

## 4. A weak facility and its answers (2½ min)

**Show:** the **Facility view** tab, still filtered to Birnin Gwari.

**Say:** "Facilities are listed **weakest first**. The old report listed the best first, which
hid the ones needing support at the bottom."

**Click:** **PHC Kufana 3** (overall **62.2, Needs action**). The detail panel opens.

**Say**, pointing as you go:

- "The score card: each dimension and the overall for this quarter."
- "**Item responses**: every question the assessor answered, month by month, as Pass, Fail or
  N/A. This is where a score comes from. The facility's team can see which register or form
  failed."
- "**Overall by round**: how this facility has moved over time."
- "**Flags**: unusual results, such as a big jump from last quarter, are flagged for a second
  look. They are not hidden."
- "**Changes**: any correction to this facility's assessment, who made it, when and why."
- "The panel has its own link, so it can be sent to a colleague and opens straight to this
  facility."

Press **Esc** to close.

## 5. The quarantine story (2 min)

**Say:** "In the old report, rows like this one appeared in the published figures."

| What the old report showed | Why it cannot be true |
|---|---|
| No local government | Every facility belongs to one of the 23 |
| Ward "Quarterly" | The columns had shifted; a month landed in the ward |
| Facility "2019.00" | A number in the facility column |
| A score of **347.66** | Scores run from 0 to 100 |

**Show:** the Data issues API tab (http://localhost:8080/api/quarantine?group=rule). Refresh it
if it was opened before the setup command ran.

**Say:**

- "Here the same row was sent through the system's checks. It is **held in quarantine** with all
  four reasons: shifted columns, unknown local government, unknown facility, and a score out of
  range."
- "It is **not** in any average. Go back to Home and the state is still **86.0** from **439**
  facilities, and the LGA count is still **23**."
- "Nothing is deleted. The original record is kept exactly as it was sent. An administrator can
  correct it, reject it with a reason, or leave it held. The Data issues page for doing that
  comes in the next phase; today you are seeing the data underneath it."

## 6. What is deliberately next (1 min)

**Show:** the **Implementation plan** tab.

**Say:**

- "The plan is generated from the results. Every local government scoring below Acceptable gets
  the standard action for its weakest dimension, an owner and a review point. This quarter that
  is **Birnin Gwari** and **Kauru**. The others are listed as needing no action."
- "Owners, dates and statuses become editable, with every change recorded, in Phase 6, with the
  Excel workplan."

Then go through the list below.

## Known gaps (say these plainly)

- **No ODK connection yet.** All data is synthetic. Connecting to ODK Central needs the server
  address, a read-only project account, and the project and form IDs (open questions Q-01 and
  Q-11). Then the real form versions are mapped and historical rounds are loaded.
- **No editing yet.** The implementation plan is read-only. Owners, review dates and statuses
  come in Phase 6.
- **No administration pages yet.** These are ODK pull, Data issues (correct, reject or keep held
  records), Rounds, the Facility list and Scoring rules. They come in Phase 5. The checks and
  the quarantine already work underneath.
- **No exports yet.** CSV, Excel, the plan workbook and the PDF round report come in Phase 6.
- **On very slow connections the first view takes about 4 seconds to appear.** That is on 3G
  at 400 Kbps. A fix, rendering the numbers on the server, is proposed for the hardening phase.
- **Screen-reader testing by people** (NVDA, TalkBack) is still to do. The automated checks pass
  on every page.

## Questions the team may ask

- **"Can we change the thresholds?"** Yes. They are in versioned scoring rules. Publishing a new
  version re-scores open rounds and is recorded. Closed rounds keep the version they were
  published with.
- **"Which LGA's figure is right if a facility moves ward?"** A facility counts where the master
  list puts it now. This is assumption A4, to confirm with the team.
- **"How do you know the numbers match ours?"** The scoring is tested against the published
  reference figures. Matching a real published round waits for the ODK connection.
