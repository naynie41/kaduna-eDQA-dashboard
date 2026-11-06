# Public dashboard impact analysis (Phase P, step 0)

6 Nov 2026. The six documents now make the dashboard public and read-only, with administration in
a separate `/admin` area (decisions **D-76–D-83**, written as D-27–D-34 in the new documents; see
§1). This file lists what in the code assumes the dashboard is behind login, and what would expose
something on the SECURITY.md §3.1 list if it were made public. No code was changed in this step.

Read with: CLAUDE.md rules 3 and 15, SECURITY.md §3 and §3.1, ARCHITECTURE.md §8, §9.0–9.3 and §11.1.

## The P steps used below (proposed)

No document breaks Phase P into steps, so this file uses the split below. Every inventory row
names one step. Adjust the split if you prefer another, and the rows move with it.

| Step | What it does | Done when |
|---|---|---|
| **P.1 Routes and guards** | Two route groups (SECURITY.md §3): public (`public.readonly`, `throttle:public`, `noindex`) and admin (`admin.ip`, `auth`, `noindex`, prefix `/admin`). Admin JSON moves to `/admin/api/*`. Old URLs redirect. After login → `/admin`, after logout → `/` (D-78). `robots.txt` plus an `X-Robots-Tag` header (D-82). The route classifier gets the public class | The route guard passes with five classes; public routes answer 405 to anything but GET/HEAD |
| **P.2 PublicScope** | Reporting returns closed rounds only to anyone who is not a signed-in administrator, behind `config('edqa.public.show_open_round')` (D-77). This covers filter options, round selection, every aggregator and the cache keys | An anonymous request never sees an open-round figure (test per aggregator) |
| **P.3 Public data contract** | `Http\Resources\Public\*` and the `/api/public/*` endpoints (ARCHITECTURE.md §8). A public facility detail with no §3.1 field. Plan owners shown as roles (D-79). The leak test (D-83) | The leak test passes over every public route and endpoint |
| **P.4 Public shell and pages** | `Http\Controllers\Public\*`, the public URLs (`/`, `/facilities`, `/lgas`, `/monitor`, `/visuals`, `/plan`), the public shell (seal, title, filters, tabs, footer "Figures last updated", D-81), the public drawer. No admin or login link (D-78) | Six public pages at 360 and 1280 px, axe clean, no link to `/login` |
| **P.5 Admin side** | The admin shell (rail with "View public dashboard", status bar, logout). The admin preview bar and open-round data when an administrator views the public pages. The admin facility view at `/admin/facilities/{id}`, with flags, attachments, audit, source link and corrections | An administrator works only under `/admin` and can preview the public site |
| **P.6 Tests, budgets, handover** | Page tests split anonymous / admin. Playwright projects split public (no stored session) / admin. Public pages served from cache and load-tested at 200 anonymous visitors (ARCHITECTURE.md §10). CHANGELOG, plus the `edqa-infra` notes (rate limits, cache, robots) | `make check` and `make e2e` green on both surfaces |

---

## 1. Decision numbering (done in this step)

**They clashed, so they were renumbered.** In the committed record, D-27–D-35 already meant other
things, and the code cites those meanings:
- D-27 item weights; D-28 a visit's round; D-29 assessor identity; D-30 session time zone;
- D-31 quarantine resolution; D-32 rule versions pinned to rounds (39 citations); D-33
  `round_aggregates`; D-34 rescore; D-35 round lifecycle.

The new documents also undid the K.0 renumbering (`docs/kobo-impact.md` §2.1). The Kobo decisions
were back at D-21–D-26, and D-17–D-20 and D-36–D-75 were missing.

What was done, following the K.0 precedent:

| New documents said | Now | Meaning |
|---|---|---|
| D-21–D-26 | **D-59–D-64** (the K.0 numbers again) | KoboToolbox decisions. The rows were word for word the old D-59–D-64 |
| D-27 | **D-76** | Public read-only dashboard; admin under `/admin` |
| D-28 | **D-77** | Public data is closed rounds only (`PublicScope`) |
| D-29 | **D-78** | No login link; `/login` by URL only; redirects after login and logout |
| D-30 | **D-79** | Public plan read-only; owners as roles |
| D-31 | **D-80** | Exports admin-only |
| D-32 | **D-81** | Public status is only "Figures last updated" |
| D-33 | **D-82** | `noindex` everywhere for now |
| D-34 | **D-83** | Public resources and a leak test |
| D-35 | **D-84** | No MFA. Outside the range the prompt named, but it clashed with D-35 round lifecycle in the same way |

- **§11.1** holds D-01–D-84 again, each number once. D-01–D-75 are the committed rows. D-02 keeps
  the new wording ("2FA removed by D-84"). D-20, D-24 and D-26 (the 2FA decisions) are marked
  "Superseded by D-84". D-38 notes that `otplib` was removed.
- **References renumbered:** ARCHITECTURE.md §1, §9.0 and §11.2; CLAUDE.md; SECURITY.md;
  CONVENTION.md; and this session's no-MFA entry in CHANGELOG.md.

**Left for the next code step (no code changed here):**

| What | Where | Fix | Step |
|---|---|---|---|
| The no-MFA work (uncommitted) cites **D-35** where it means D-84 | `routes/web.php:46`, `config/edqa.php:12`, `config/fortify.php:153`, `app/Providers/FortifyServiceProvider.php:20`, `app/Models/User.php:19`, `app/Console/Commands/CreateAdmin.php:17`, `database/seeders/E2eAdminSeeder.php:13`, both `2026_11_06_*` migrations, `tests/e2e/auth.setup.ts:6`, `tests/Feature/Auth/{NoMfaTest,RouteGuardTest}.php`, `tests/Feature/Database/{UsersTableTest,SignInControlsTablesTest,E2eAdminSeederTest}.php` | D-35 → D-84 in comments | P.1 (or now, before the no-MFA commit) |
| Phase 5 step 2 code cites **D-24** for deletions at source, which is D-62 | `AssessmentStatusChanged`, `WithdrawAssessment`, `DeletionsAtSourceQuery`, `QuarantineQuery`, `ValidationContextFactory`, `AssessmentStatusController`, the `add_withdrawn_assessments` migration, and their tests (25 citations) | D-24 → D-62 in comments | P.1 |
| CLAUDE.md rule 1 calls the facility master-list import **D-19**, but D-19 is Wayfinder. This was already so before Phase P | `CLAUDE.md` | Owner: give the import its own decision, or drop the number | — |
| **Open questions:** the new §11.4 has lost Q-19, Q-22 and Q-23. The code cites Q-23 (source edit over a correction, 7 times) and Q-25–Q-32, which are not in the table | ARCHITECTURE.md §11.4 | Owner: restore as K.0 proposed for Q-numbers (`docs/kobo-impact.md` §2.2) | — |

---

## 2. Inventory: what assumes login, or would leak

### 2.1 Routes (`routes/web.php`, `routes/settings.php`)

Today one group, `['admin.ip', 'auth']`, holds everything except Fortify's screens, the webhooks
and `/up`.

| Route(s) | Today | Problem | Step |
|---|---|---|---|
| `GET /`, `GET /dashboard` (`DashboardRedirectController`) | Signed in; redirect to `/dashboard/home` | `/` must be the public Home | P.1, P.4 |
| `GET /dashboard/{home,facility,lga,monitor,visuals,plan}` (`dashboard.*`) | Signed in | Become `/`, `/facilities`, `/lgas`, `/monitor`, `/visuals`, `/plan` (`public.*`), GET only, rate limited. Old URLs redirect | P.1, P.4 |
| `GET /api/facilities/{facility}` | Signed in; full admin detail | Public drawer gets `/api/public/facilities/{id}` (P.3). This one becomes `/admin/api/facilities/{id}` | P.1, P.3 |
| `GET /admin`, `/admin/{sources,odk,data-issues,rounds,facilities,scoring-rules}` | Under `/admin` already | Stay. `admin.*` names already match CONVENTION.md | P.1 (middleware only) |
| `GET|POST /api/{sources*, pulls/*, quarantine*, facility-options, submissions/*/deletion-review, assessments/*/{withdraw,restore,corrections,flags/*/override}, scoring-rules*, rounds*, rescores/*, facility-list/export-cascade*, backfill-reports/*}` | `/api/*`, signed in | Move to `/admin/api/*` (ARCHITECTURE.md §8). `/api/` is now the public prefix | P.1 |
| `GET /attachments/{submission}/{filename}` | Signed in | Evidence (§3.1): `/admin/attachments/…` | P.1 |
| `GET /settings`, `/settings/security`, `PUT /settings/password` | Signed in | Admin-only: `/admin/settings/…` | P.1 |
| `GET /dev/components` | Signed in, local only | Keep behind admin (`/admin/dev/components`) | P.1 |
| Fortify `home` = `/` (`config/fortify.php:79`) | After login → Home | After login → `/admin`; after logout → `/` (D-78). Fortify's `LogoutResponse` needs binding | P.1 |
| Fortify screens, webhooks, `/up` | Outside the group | Unchanged. Login stays reachable only by URL | — |
| `robots.txt`, `X-Robots-Tag` | Missing | D-82: `noindex` on every response; `robots.txt` disallows `/admin` and `/login` | P.1 |
| Route groups have no `public.readonly`, `throttle:public` or `noindex` | — | New middleware (SECURITY.md §3, §8) | P.1 |

### 2.2 Controllers

| Controller | Problem | Step |
|---|---|---|
| `Http\Controllers\Dashboard\{Home,Facility,Lga,Monitor,Visuals,Plan}Controller` | Become `Http\Controllers\Public\*` (CONVENTION.md: public controllers only read and only use `Public\*` resources). Each returns aggregator DTOs straight as props: P.3 puts public resources between | P.4, P.3 |
| `Dashboard\MonitorController` (`sources` prop, `SourceCountsPayload`, 2C.7) | Assessments per source (ODK Central / KoboToolbox) on a public page. §3.1 does not list counts, but D-81 makes source information admin-only. **Owner decision:** recommend admin preview only | P.3 |
| `Dashboard\PlanController` | Public read-only is fine. Stored `plan_actions` owners must be roles (see §2.4) | P.3 |
| `Dashboard\DashboardRedirectController` | `/` becomes Home itself. Keep only as the old-URL redirect | P.1 |
| `Facilities\FacilityDetailController` (`FacilityDetailResource`) | Admin detail (flags, attachments, audit, assessor, source link). Admin-only; a new `Public\FacilityDetailController` serves the public drawer | P.3 |
| `Admin\AdminSourcesController`, `Quarantine\*`, `Assessments\*`, `Rounds\*`, `Scoring\*`, `Sources\*`, `Submissions\*`, `Odk\*`, `Attachments\*`, `Facilities\{CascadeExport,FacilityOptions}Controller`, `Settings\SecurityController`, `DevComponentsController` | Admin. Move under `Http\Controllers\Admin\*` (CONVENTION.md) as their routes move | P.1 |

### 2.3 Middleware and Inertia shared props

| What | File | Problem | Step |
|---|---|---|---|
| `auth.user` (`id`, `name`, `email`) | `HandleInertiaRequests::share` | Null for visitors, so public pages are fine. An administrator previewing a public page sends their name and email in the page JSON: allowed (it is their own), but keep it out of the public HTML cache | P.5, P.6 |
| `shell` (assessments, per-source status, quarantine count) | `HandleInertiaRequests::share` | Signed-in only today, so it doesn't leak. Admin shell only; the public shell gets `status: { figuresUpdatedAt }` (D-81) | P.4, P.5 |
| `admin` (open issue count, stale ingestion) | `HandleInertiaRequests::share` | Gated on `admin.*` and signed-in already | — |
| `translations`: `common`, `auth`, `dashboard` on every page | `HandleInertiaRequests::TRANSLATION_GROUPS` | Public pages would carry admin-only text: the `dashboard.drawer.*` correction, flag, attachment, activity, instance and "Open in :source" strings; `common.nav.administration`; `common.user_menu`; `common.sync.*`; and the whole `auth` group. Not data, but it advertises admin features, and the leak test greps keys such as `instance`. Split: a public group plus admin-only groups | P.4 |
| `pageGroups()` keyed on `dashboard.*` | `HandleInertiaRequests` | Route names change to `public.*` | P.4 |
| `ShareDashboardContext`: `filterOptions` (years and quarters) | `Reporting\Queries\FilterOptionsQuery` | Lists every round, open ones too. Closed only for visitors (D-77) | P.2 |
| `ShareDashboardContext`: `ReportFilter::resolve()`, default round | `ShareDashboardContext`, `RoundSelection` | The default "latest round" can be the open one | P.2 |
| `ShareDashboardContext`: `summary` | `StateSummaryAggregator` | Through the PublicScope | P.2 |
| `EnforceAbsoluteSessionLifetime` in the `web` group | `bootstrap/app.php` | Runs on public pages too. Harmless for visitors; keep it out of public page caching | P.6 |
| `admin.ip` and `auth` | `routes/web.php` | The public group must have neither; the allow-list stays on `/login` and `/admin/*` only (SECURITY.md §2) | P.1 |

### 2.4 Reporting, resources and the data they carry (§3.1)

| Item | File | Exposes if public | Step |
|---|---|---|---|
| Every aggregator (`StateSummary`, `Dimension`, `OwnerSplit`, `QuarterTrend`, `WeakestLgas`, `WeakestWards`, `MonthSlot`, `LgaTable`, `Coverage`, `AssessmentWindow`, `ImplementationPlan`, `FacilityScoresQuery`, `AssessmentsBySource`) | `app/Domain/Reporting/Aggregators/*` | Open-round figures. No PublicScope exists yet (D-77) | P.2 |
| Report cache | `ReportCache` callers | Keys include the round IDs, so public (closed only) and admin preview (with open) stay apart once the scope changes the round selection. Any key **not** built from round IDs must add the scope | P.2 |
| `FacilityDetailAggregator` / `FacilityDetail` DTO | `app/Domain/Reporting/…` | `assessment.assessor`; `assessment.corrected.by` (admin name); `flags` (soft flags, override by and reason); `attachments` (names and URLs); `activity` (audit: actor names, old/new, reasons); `source` (key, `instance_id`, deep link); `rounds[].open` and open-round scores | P.3 (public DTO), P.5 (admin view keeps all) |
| `FacilityDetailResource`, `AssessmentFlagsResource`, `Admin\FlaggedAssessmentResource`, `Assessments\AssessmentCorrectionResource`, `Quarantine\*`, `Rounds\RoundResource`, `Scoring\*`, `Sources\PullRunResource` | `app/Http/Resources/*` | Admin output. None may be reused publicly (D-83). Move to `Http\Resources\Admin\*` | P.3 |
| No `Http\Resources\Public\*` exist | — | Needed for every public prop and `/api/public/*` (CONVENTION.md) | P.3 |
| `ImplementationPlanAggregator`: stored `plan_actions.owner` | `ImplementationPlanAggregator`, `plan_actions` | Free text, so it could hold a personal name. D-79: roles only. Default owners already come from `lang/en/plan.php` roles. **Owner decision:** constrain the column to the role list, or map it on output | P.3 |
| `AssessmentWindowAggregator` (first and last visit dates per round) | Monitor | Dates are fine; closed rounds only | P.2 |
| `FacilityScoresQuery` rows | Facility view | No assessor or source fields today; closed rounds only | P.2 |

### 2.5 Frontend: layouts, components, pages, types

| File | Assumes login / would leak | Step |
|---|---|---|
| `layouts/AppShell.tsx` | One shell for everything: `Rail`, `SyncStatusBar`, `TopBar` with the user menu and logout. Split into `PublicShell` and `AdminShell` | P.4, P.5 |
| `components/Rail.tsx` | Links to `/dashboard/home` and **`/admin` ("Administration")**: a link to the admin area on public pages (D-78). Admin rail only, plus "View public dashboard" | P.4, P.5 |
| `components/TopBar.tsx` | Security settings and **Sign out** buttons; uses `@/routes` `logout` | P.4, P.5 |
| `components/SyncStatusBar.tsx` | Per-source sync state, last error and quarantine count: source health (D-81). Admin only; public footer "Figures last updated" | P.4 |
| `components/StaleIngestionBanner.tsx` | Admin only (already gated by the `admin` prop) | — |
| `components/DashboardTabs.tsx`, `lib/pageMeta.ts` | Hard-coded `/dashboard/${page}` URLs and `dashboard/*` component names | P.4 |
| `components/FilterBar.tsx` | Year and Duration from `filterOptions`: closed rounds only once P.2 filters the options | P.2 |
| `components/FacilityDrawer.tsx` | Fetches `@/routes/api/facilities` `show` (admin detail). The public drawer fetches `/api/public/facilities/{id}` | P.3, P.4 |
| `components/FacilityDetailView.tsx` | Renders assessor, flags, attachments, the activity (audit) trail, source tag and deep link, the corrected note and `AssessmentCorrection`. Public version: scores, items and history only. This one moves to the admin facility view | P.4, P.5 |
| `components/AssessmentCorrection.tsx`, `ConfirmWithReasonDialog.tsx`, `lib/corrections.ts`, `components/SourceTag.tsx`, `components/HistorySparkline.tsx` | Corrections are admin (P.5). `HistorySparkline` is reused by the public drawer | P.5 |
| `pages/dashboard/*.tsx` | Rendered inside `AppShell`; `facility.tsx` opens the admin `FacilityDrawer`; `monitor.tsx` shows the per-source counts | P.4 |
| `pages/admin/*.tsx`, `components/Admin*`, `DataIssue*`, `Deleted*`, `Pull*`, `Source*`, `Backfill*`, `Cascade*`, `Deletions*`, `RuleSummary`, `CorrectIssueForm`, `DuplicateSides`, `ProgressPanel` | Admin already. Their `@/routes/api/*` imports change with `/admin/api/*` (Wayfinder regenerates) | P.1 |
| `pages/settings/security.tsx`, `pages/auth/*`, `layouts/AuthLayout.tsx` | Admin and auth screens. Login has no link from public pages (it never had one) | P.1 |
| `types/shell.ts`, `types/global.d.ts` (`shell`, `admin`, `auth` page props) | Public page props differ: no `shell`, a `status` with the figures date, `auth.user` only for the admin preview | P.4 |

### 2.6 Tests

| Test | Assumes login | Step |
|---|---|---|
| `tests/Feature/Dashboard/{DashboardPages,DurationPages,FacilityDetail,FacilityPage,HomePage,LgaPage,MonitorPage,PlanPage,VisualsPage}Test.php`, `tests/Feature/Performance/ServerTimingTest.php`, `tests/Feature/Reporting/KoboItemLabelsTest.php` | All `actingAs($admin)`, against `dashboard.*` URLs. Each needs an anonymous version (closed rounds only, no §3.1 field) and an admin-preview version | P.4, P.6 |
| `tests/Feature/Auth/RouteGuardTest.php` | "Refuses every other route to an unauthenticated visitor"; the classifier knows auth / webhook / health / signed-in. Needs the **public** class, GET-only checks and `/admin/*` → `/login` | P.1 |
| `tests/Feature/Auth/AdminIpAllowListTest.php` | Lists `/dashboard/home` as allow-listed. Public pages must **not** be (SECURITY.md §2: `/login` and `/admin/*` only) | P.1 |
| `tests/Feature/Auth/{NoMfa,SessionLimits,EmailVerification}Test.php` | Use `/dashboard/home` as "a signed-in page" | P.1 |
| `tests/Feature/Http/*ApiTest.php`, `tests/Feature/Admin/*`, `tests/Feature/Ingestion/{SourcesApi,Attachments}Test.php`, `tests/Feature/Facilities/CascadeExportTest.php`, `tests/Feature/Assessment/CorrectAssessmentTest.php`, `tests/Feature/Settings/SecurityTest.php` | `/api/*`, `/attachments/*` and `/settings/*` URLs | P.1 |
| Leak test, PublicScope tests, public resource tests | Missing | P.3, P.2 |
| `resources/js/components/{ShellComponents,FacilityDrawer,AssessmentCorrection,HomeHero,LgaView,Visuals}.test.tsx`, `lib/architecture.test.ts` | One shell; the drawer fixture is the admin detail | P.4, P.5 |
| `playwright.config.ts` | `desktop` and `phone` depend on `setup` and use the stored admin session (`AUTH_STATE`) for every spec | P.6 |
| `tests/e2e/{home,facility,lga,monitor,visuals,plan,drawer,shell,audit,screenshots}.spec.ts` | Dashboard specs run signed in, against `/dashboard/*`; `drawer.spec.ts` expects admin fields; `shell.spec.ts` the rail and sign out | P.6 |
| `tests/e2e/{admin,corrections,data-issues,sources,odk-mock}.spec.ts` | Admin specs; the corrections journey starts from the dashboard drawer and moves to the admin facility view | P.5, P.6 |

### 2.7 Strings (`lang/en`)

| Keys | Problem | Step |
|---|---|---|
| `common.nav.administration`, `common.user_menu.*`, `common.sync.*`, `common.shell.*` | Admin shell only | P.4 |
| `dashboard.drawer.{flags_*, attachments_*, activity_*, events.*, correct.*, corrected_note*, its_source, instance, open_in_source}` | Admin facility view only. Move to an admin group so public pages don't load them | P.4, P.5 |
| `dashboard.pages.*` and `dashboard.tabs.*` subtitles | Check each for "signed-in" wording; the subtitles count facilities in view, which is fine | P.4 |
| New strings | Public footer ("Figures last updated :date"), admin preview bar ("Admin preview, includes the open round · Back to admin"), "View public dashboard" | P.4, P.5 |

---

## 3. Built in Phase 5 and 2C.7 that moves under /admin

| Built in | What | Where it is now | Where it goes | Step |
|---|---|---|---|---|
| Phase 5 step 0 (D-55) | Admin shell: rail, admin tabs, Data issues badge, stale banner | `AppShell` + `Rail` (shared with the dashboard) | `AdminShell` only; the public shell has none of it | P.5 |
| Phase 5 steps 1 and 2C (D-56) | Data sources page and its JSON (`/api/sources*`, `/api/pulls/*`, `/api/backfill-reports/*`, `/api/facility-list/export-cascade*`) | Page under `/admin`; JSON under `/api` | JSON under `/admin/api` | P.1 |
| Phase 5 step 2 (D-57, D-62) | Data issues page; quarantine, deletion review and withdraw/restore JSON (`/api/quarantine*`, `/api/submissions/*/deletion-review`, `/api/assessments/*/{withdraw,restore}`, `/api/facility-options`) | Page under `/admin`; JSON under `/api` | JSON under `/admin/api` | P.1 |
| Phase 5 step 3 (D-58, Q-23) | **Assessment corrections**, the soft-flag override, and the "Corrected in the portal" note: `AssessmentCorrection` inside the dashboard facility drawer; `POST /api/assessments/{id}/corrections` and `…/flags/{code}/override` | Dashboard drawer, reached from the Facility view | **Admin facility view `/admin/facilities/{id}`** (ARCHITECTURE.md §9.2.7); JSON under `/admin/api`. The public drawer shows none of it | P.5 |
| Phase 4 step 5 (D-42), extended in 5 and 2C | Facility drawer with flags, attachments (`/attachments/…`), audit trail, source tag and deep link | Dashboard drawer | Admin facility view; attachments at `/admin/attachments/…` | P.5, P.1 |
| Phase 2C step 7 | `assessments.source_id` and per-source counts (`AssessmentsBySourceAggregator`, `SourceCountsPayload`) on the **Monitor** page | Monitor (signed in) | Admin preview only, unless the owner decides counts by source are public (§2.2) | P.3 |
| Phase 2C (D-59–D-64) | Per-source sync status bar (`SyncStatusBar`, `shell.sources`) | Every signed-in page | Admin shell; the public site gets "Figures last updated" only (D-81) | P.4 |
| Rounds, Scoring rules, Facility list pages and JSON | Pages under `/admin` (placeholders for Rounds, Facility list and Scoring rules); JSON `/api/{rounds*, rescores/*, scoring-rules*}` | Under `/admin` | JSON under `/admin/api` | P.1 |
| Security settings (password change, no-MFA step) | `/settings/security` | Signed in, outside `/admin` | `/admin/settings/security` | P.1 |

**Name clash to plan for:** `/admin/facilities` is the Facility list page, and ARCHITECTURE.md
§9.2.7 puts the admin facility view at `/admin/facilities/{id}`. These can coexist (list and show),
but the Facility list placeholder and the new view should be built as one resource.

## 4. Decisions needed before P.3

1. **Counts by source on the public Monitor page** (2C.7): public, or admin preview only?
   Recommendation: admin only (D-81 spirit).
2. **`plan_actions.owner`**: constrain to the role list in the database, or map to roles on public
   output (D-79)? Recommendation: constrain. A role list in config, and a CHECK or an enum.
3. **Old URLs**: `/dashboard/*` permanent redirects to the new public URLs (bookmarks keep
   working), or 404? Recommendation: redirect.
4. **Open questions table**: restore Q-19, Q-22 and Q-23 (§1), which the code cites.
