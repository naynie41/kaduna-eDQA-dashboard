# Phase 4 audit: performance budgets and accessibility

Kaduna eDQA Portal, dashboard (Phase 4). Audit date: 17 October 2026.

This covers the six dashboard pages (Home, Facility view, LGA view, Monitor, Visuals,
Implementation plan), the facility detail drawer, and the other signed-in and sign-in pages
(Administration landing, Security settings, Confirm password, Sign in).

`ACCESSIBILITY.md` is not yet in the repository, so the accessibility standard applied is
**WCAG 2.2 level AA** (CLAUDE.md, project documents), with CONVENTION.md §6 for colour.

## Summary

| Area | Target | Result |
|---|---|---|
| Server response | p95 < 1.5 s, any page, any filter | **Met.** Slowest p95 265 ms on ~44,000 assessments |
| First-load JavaScript | ≤ 300 KB gzipped | **Met.** 128.9 KB |
| Charts load after numbers | Charts last | **Met.** Tested on Home and Visuals |
| First paint on throttled 3G | < 3 s | **Not met.** LCP ≈ 6.5 s on Slow 3G. Exception E1 below |
| axe, WCAG 2.2 A/AA | No violations | **Met.** 0 violations, every page and the drawer, at 360 and 1280 px |
| Forced colours (High Contrast) | Every state visible | **Met after fixes.** Five components fixed (F1) |
| 200% zoom | No loss of content, no sideways scroll | **Met.** |
| Reduced motion | No motion when asked | **Met.** |
| Keyboard only | Main journeys | **Met.** Both journeys, at both widths |
| Screen readers (NVDA, TalkBack) | Usable | **To do: manual.** Checklist below |

## 1. Server performance

**Dataset.** `make artisan c="edqa:seed:perf"` loads five years of quarterly rounds, with one
scored assessment per active facility. That gives 43,520 assessments over 20 rounds, about
392,000 score rows. Its scores are derived from generated item responses using the scoring
formula. A test re-scores every assessment with the real calculator and confirms they agree.
This dataset is for local use only and is never loaded in production.

**Method.** `make test-slow` (`tests/Feature/Performance/ServerTimingTest.php`) times each of
these over ten filter combinations:

- every dashboard page's first response;
- its deferred-data request;
- each read-only API endpoint.

Every combination is a first request, so nothing comes from the cache. Times are server
processing time, measured in-process.

**Before and after.** The only slow path was the Visuals month charts. With no year chosen they
recomputed every round from the raw scores. They now read pre-computed figures
(`round_aggregates` v4, ARCHITECTURE.md D-46), which are checked against the original
calculation to nine decimal places.

| Endpoint | p95 before (ms) | p95 after (ms) |
|---|---:|---:|
| Home (page) | 393 | 265 |
| Home (deferred data) | 425 | 235 |
| Facility view (page) | 61 | 99 |
| Facility view (deferred table) | 252 | 187 |
| LGA view (page) | 217 | 214 |
| LGA view (deferred table) | 56 | 25 |
| Monitor (page) | 60 | 49 |
| Monitor (deferred data) | 220 | 251 |
| Visuals (page) | 59 | 60 |
| **Visuals (deferred charts and rankings)** | **1,263** | **48** |
| Implementation plan (page) | 57 | 76 |
| Implementation plan (deferred plan) | 52 | 20 |
| Facility drawer API | 391 | 64 |
| Rounds API | 34 | 12 |
| Scoring rules API | 9 | 8 |
| Data issues API | 67 | 28 |

Small differences between the two runs, either way, are noise on the development machine; only
the Visuals change is a real one. The full tables are in
`storage/app/perf/server-timing-before.md` and `server-timing.md`.

## 2. Client performance

**Method.** `make lighthouse` runs Lighthouse 13.5 three times per page against a production
build, and the median run is reported. Throttling is simulated Slow 3G: 400 Kbps, 400 ms
round trip, CPU slowed 4×, on a 360 × 740 phone screen. PHP runs as it does in production for
the measurement, because the development setting adds about 6 s to every request on this
machine.

| Page | Server response (s) | First contentful paint (s) | Largest contentful paint (s) | JS transferred (KB) | All bytes (KB) |
|---|---:|---:|---:|---:|---:|
| Home | 0.31 | 4.26 | 6.49 | 235.3 | 359.8 |
| Facility view | 0.30 | 4.29 | 6.54 | 156.5 | 283.1 |
| Visuals | 0.59 | 4.29 | 6.53 | 231.2 | 357.7 |

The JS figures include the lazily loaded chart code, which arrives after the first paint. The
first-load JavaScript, measured by `make size`, is 128.9 KB.

**Charts load after numbers.** This is tested in `tests/e2e/audit.spec.ts` on Home and Visuals,
using the browser's own clock. On both pages:

- the figures paint before any chart does;
- the chart code is requested only after the page's data has arrived.

## 3. Accessibility results

### Automated (axe 4, WCAG 2.2 A and AA tags), 360 px and 1280 px

**No violations, at any impact level**, on all of these:

- Home, Facility view, LGA view, Monitor, Visuals, Implementation plan;
- the facility drawer;
- Administration, Security, Confirm password and Sign in.

Every result is saved in `storage/e2e/a11y/`.

axe could not decide some colour-contrast checks automatically and marked them for a person to
judge. They were reviewed against the design tokens and need no change:

| axe's reason | Where | Assessment |
|---|---|---|
| `nonBmp` (symbol characters) | Sort arrows, status symbols (●, ◐, ▲), Pagination "…" | Decorative or repeated in text; the text carries the meaning |
| `elmPartiallyObscured` | Table rows scrolled sideways out of view at 360 px | Same colours as the visible rows, which pass |
| `bgGradient` | The Home hero card | Ink text on the hero's near-white gradient; passes at both ends |
| `imgNode`, `shortTextContent` | Chart axis labels inside the chart images | The same figures are in each chart's table (Show as table) |

### Forced colours (Windows High Contrast)

Screenshots: `storage/e2e/screenshots/forced-*.png`. Every page's text, borders, focus
outlines, chart labels and band words stay visible.

**F1 (found and fixed).** Five controls showed their selected state only with a background fill.
Forced colours replace backgrounds, so the selected option became indistinguishable from the
others:

- the measure switcher and the Overall / Public / Private view;
- the current page number;
- the pressed LGA tile;
- the current tab;
- the current section in the side rail.

Each now uses the system highlight colours in forced colours. A test fails if any pressed or
current control looks the same as its neighbours in that mode.

### 200% zoom

Screenshots: `storage/e2e/screenshots/zoom200-*.png`. 200% zoom of a 1280 × 800 window was
emulated as a 640 × 400 viewport at double resolution. Every page reflows into one column with
nothing clipped, and the page never scrolls sideways (asserted). Wide tables scroll inside
their own labelled, keyboard-focusable region.

### Reduced motion

With `prefers-reduced-motion: reduce`, the loading placeholders stop pulsing and no animation
is left running on any dashboard page (both asserted). The dimension bars' width transition is
also turned off by the same rule.

### Keyboard only

Both journeys are tested at 360 and 1280 px. Every control is reached with Tab presses, not
programmatic focus:

1. **Filter → LGA tile → facility → drawer → close.**
   - Change the LGA filter with the arrow keys.
   - Open the LGA view from its tab and choose another LGA tile; it becomes pressed and keeps
     focus.
   - Open the Facility view and open the first facility.
   - Focus lands on the drawer heading, and Tab reaches Close. Esc closes the drawer, and focus
     returns to the facility name.
2. **Sort and paginate the facility table.**
   - Sort by Ward (A to Z, then Z to A); focus stays on the sort button.
   - Tab to Next and press Enter. Page 2 loads, and the change is announced.

The drawer's focus trap (Tab and Shift+Tab never leave it) is covered in
`tests/e2e/drawer.spec.ts`.

## 4. Exceptions

| # | Exception | Why | Fix and date |
|---|---|---|---|
| E1 | First paint on Slow 3G is about 4.3 s (LCP about 6.5 s), against a 3 s target | The pages render in the browser. Nothing can paint before about 150 KB of HTML, CSS and JavaScript arrives, which is about 3 s of transfer at 400 Kbps. The five font files (98 KB) then hold the largest paint back another 2 s or so. The plan's "numbers render before hydration" needs server-side rendering, which is not built | **Owner decision needed (ARCHITECTURE.md Q-31).** Proposed: Inertia server-side rendering in Phase 7 hardening, with the budget re-measured by `make lighthouse`. Target date: end of Phase 7. Reducing font weights would help but is a design change |
| E2 | Screen-reader behaviour is not yet confirmed with real assistive technology | Automated tools cannot judge announcements, reading order or how verbose things are | Manual run with the checklist below, before UAT (Phase 9). Target date: before UAT starts |

## 5. Manual screen-reader checklist

Use the local stack (`make up`, sign in at http://localhost:8080) with the demo data. Run once
with **NVDA** (current version) and **Firefox or Chrome on Windows**, and once with
**TalkBack** and **Chrome on an Android phone**. For TalkBack, open the dev stack from the phone
over the local network, or use the staging server when it exists.

Mark each line Pass, Fail (with what was heard) or N/A.

### Every page

- [ ] The page title is read on arrival ("Home · Kaduna eDQA" or similar), and focus is on the
      page's heading after moving between tabs.
- [ ] "Skip to main content" is the first thing reached and moves to the content.
- [ ] Landmarks are listed (NVDA: D / Shift+D; TalkBack: landmark navigation): banner,
      navigation (Main, Account, the dashboard tabs), search (Filters), main.
- [ ] Headings make a sensible outline (NVDA: H; TalkBack: heading navigation).
- [ ] The sync status line ("ODK Central not connected yet…") is read as a status.

### Filters

- [ ] Each filter is announced with its name and current value ("LGA, combo box, All LGAs").
- [ ] With no year chosen, Quarter is read as unavailable, and its hint ("Choose a year to pick a
      quarter") is read.
- [ ] After changing a filter, the result is announced once ("Showing …") without moving focus.
- [ ] "Clear filters" is read as a link and resets the filters.

### Home

- [ ] The hero reads as term and value pairs ("Average quality score, 86").
- [ ] "The three dimensions" reads each dimension with its score and band in words.
- [ ] The trend chart is read as one image with a name, and "Show as table" gives the figures.
- [ ] "Weakest local governments" reads as a table with column headers.

### Facility view and drawer

- [ ] The measure switcher reads as a group of toggle buttons, with the pressed one announced.
- [ ] Each column header announces its sort state; after a sort, the new order is announced
      ("Sorted by LGA, A to Z. Showing 1 to 50 of 439 facilities.").
- [ ] Month headers say the month ("April") or, across quarters, "Month 1 of the quarter".
- [ ] Each facility name is a button that says it opens a dialog.
- [ ] On opening, the drawer is announced as a dialog named after the facility, and reading
      starts at its heading.
- [ ] Item results are read as words ("Pass", "Fail", "N/A"), not only as icons.
- [ ] "Overall by round" is read as a sentence, and the table gives each round.
- [ ] Esc or Close returns to the facility name. (TalkBack: use the Close button; check that the
      page behind is not read while the drawer is open.)
- [ ] Pagination reads "Facility pages, navigation", with the current page announced.

### LGA view

- [ ] Each tile reads its LGA, score, band and facility count, with its pressed state.
- [ ] Pressing a tile announces the change, and focus stays on the tile.
- [ ] The table reads its caption and column headers, and Status is read in words.

### Monitor

- [ ] "Facilities visited, by local government" reads as a numbered list, each item with its count.
- [ ] The state mean is read in words above the list.
- [ ] The assessment window table reads round, window, first and last visit, and assessments.

### Visuals

- [ ] The Overall / Public / Private group reads its pressed option. With the Owner filter set,
      the explanation is read and the buttons are announced as unavailable.
- [ ] Each month chart's figure is named ("April 2026"), and its takeaway sentence is read.
- [ ] The legend reads the four series in order.
- [ ] Each ranking reads as a numbered list, and each item reads the LGA, score and band.

### Implementation plan

- [ ] The table reads each row's LGA, overall, weakest dimension, score, action, owner and review.
- [ ] "No action needed" reads each LGA with its score and band.

### Forms (Sign in, Confirm password, Security)

- [ ] Every field is announced with its label. Errors are announced, and focus moves to the
      error summary.
- [ ] The two-step sign-in code field says what to enter.

## 6. How to repeat this audit

```bash
make test-slow                  # server timing on ~44,000 assessments (and the rescore budget)
make size                       # first-load JS budget
make lighthouse                 # Slow 3G, production build (restarts vite)
make e2e c="audit"              # axe, forced colours, 200% zoom, reduced motion, keyboard journeys
```
