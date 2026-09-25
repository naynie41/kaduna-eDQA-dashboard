# CONVENTION.md — Kaduna eDQA Portal

How code in this repository is written, named, tested and committed. If a convention here
conflicts with a habit or a framework default, this file wins. Architecture lives in
`ARCHITECTURE.md`; security rules in `SECURITY.md`; accessibility in `ACCESSIBILITY.md`.

## Contents
1. General principles · 2. PHP and Laravel · 3. Database and migrations · 4. TypeScript and
React · 5. Naming · 6. Design system · 7. Strings and localisation · 8. Testing · 9. Git,
branches and reviews · 10. Definition of done · 11. Tooling configuration ·
12. Containers and Docker

---

## 1. General principles

- **Derive, don't accept.** Scores and aggregates are always computed; never read from input.
- **Business logic lives in `app/Domain/`.** Controllers, Resources and React components hold
  no rules and do no arithmetic.
- **One use case = one Action.** Single public `execute()` method, constructor-injected
  dependencies, one Pest test file.
- **Prefer quarantine to guessing.** When data is ambiguous, stop it at the gate.
- **Small, reversible changes.** Every migration has a working `down()`.
- **No new dependency without a reason** recorded in `ARCHITECTURE.md` §11.1.
- **No dead code, no commented-out code, no `TODO` without an issue reference.**

---

## 2. PHP and Laravel

### 2.1 Files and classes
- `declare(strict_types=1);` at the top of every PHP file.
- Classes `final` by default; drop `final` only for an explicit extension point.
- DTOs are `final readonly class` with promoted constructor properties.
- Full native types on every parameter, return and property. PHPDoc only where native types
  cannot express it (generics: `@return Collection<int, Facility>`, `@param list<RuleFailure>`).
- No `mixed` unless unavoidable, and then with a comment.

### 2.2 Actions
```php
declare(strict_types=1);

namespace App\Domain\Round\Actions;

final class CloseRound
{
    public function __construct(
        private readonly RefreshRoundAggregates $refresh,
    ) {}

    public function execute(Round $round, User $actor): Round
    {
        return DB::transaction(function () use ($round, $actor): Round {
            if ($round->status === RoundStatus::Closed) {
                throw RoundAlreadyClosed::for($round);
            }
            $round->update(['status' => RoundStatus::Closed, 'closed_at' => now()]);
            activity()->performedOn($round)->causedBy($actor)->event('closed')->log('round.closed');
            RoundClosed::dispatch($round);
            return $round;
        });
    }
}
```
- Writes of more than one row are wrapped in `DB::transaction()`.
- Actions throw **domain exceptions** (`App\Domain\*\Exceptions\*`) with named constructors;
  controllers translate them to 409/422.
- Actions that need a reason take it as a required `string $reason` argument — not optional.

### 2.3 Controllers, requests, resources
- One controller per page (invokable where it has one action). Max ~30 lines per method.
- Validation only in `FormRequest` classes; `authorize()` returns `true` (the route group
  handles auth — do not add checks here).
- Inertia props are built by Resources or Aggregators, never inline arrays of computed values.
- No `DB` facade or query builder calls in controllers (architecture test).

### 2.4 Models
- Explicit `$fillable`; never `$guarded = []`.
- `casts()` method with enums, `'payload' => 'array'`, `'immutable_datetime'` for timestamps.
- Relationships typed: `public function facility(): BelongsTo`.
- `Model::shouldBeStrict()` outside production; `Model::preventLazyLoading()` everywhere but prod.
- Query logic that is reused lives in a dedicated Query class or a scope; no business rules in
  scopes that hide rows (no global scopes — they are easy to forget and hard to see).
- Audited models use `LogsActivity` with `logOnly([...])->logOnlyDirty()->dontSubmitEmptyLogs()`.

### 2.5 Enums
Backed string enums in `App\Domain\*\Enums`: `Dimension`, `Severity`, `RoundStatus`,
`SubmissionStatus`, `QuarantineStatus`, `QuarantineResolution`, `Ownership`, `FacilityLevel`,
`PullTrigger`, `PlanActionStatus`, `Band`. Each has a `label(): string` via the language file.

### 2.6 Jobs and events
- Jobs implement `ShouldQueue`, declare `$tries`, `$backoff`, `$timeout`, and a `failed()` method.
- Jobs that must not overlap use `WithoutOverlapping` middleware or `ShouldBeUnique`.
- Jobs call Actions; they do not contain business logic themselves.
- Events are past-tense (`AssessmentScored`), carry IDs or models, and are dispatched **after**
  the transaction commits (`ShouldDispatchAfterCommit`).

### 2.7 Numbers
- Scores are stored `numeric(5,2)` and passed around as `float` inside `ScoreCalculator` only
  after computing from integer counts; **round only at presentation** (`Bands`/`format` helpers).
- Display rounding rule lives in one place: `App\Domain\Scoring\Presentation::round()`.

### 2.8 Config
- Tunables in `config/edqa.php` (bands defaults, weighting, soft-rule thresholds, pull interval,
  page size). Read with `config('edqa.x')` inside service providers or Actions, never in views.
- `env()` is called only inside `config/*.php`.

### 2.9 Errors and logging
- Log channel `edqa` for ingestion; structured context arrays, never interpolated strings.
- Never log ODK credentials, session tokens, full payloads, or passwords.
- User-facing error messages come from the language file.

---

## 3. Database and migrations

- Postgres only. `jsonb()`, `timestampTz()`/`timestampsTz()`, `softDeletes` **not** used
  (deactivate flags instead; audit keeps history).
- Every enum-like column gets a CHECK constraint mirroring the PHP enum.
- CHECK and complex indexes are added with `DB::statement()` in the same migration, with `down()`
  dropping them.
- Migration names describe intent: `2026_10_01_000100_create_assessment_scores_table.php`.
- Never edit a migration that has run in staging/production — add a new one.
- Raw SQL files (materialised view) live in `database/sql/` and are loaded by migrations.
- Foreign keys always declared; `cascadeOnDelete()` only for pure children
  (`item_responses`, `assessment_scores`).
- Factories exist for every model; states for common variants (`->quarantined()`, `->private()`,
  `->closed()`).

---

## 4. TypeScript and React

- `strict: true`, `noUncheckedIndexedAccess: true`, `exactOptionalPropertyTypes: true`.
- No `any`. No `as` casts except narrowing from `unknown` after a runtime check. No non-null `!`
  without a comment.
- Function components only; props typed with an exported `type XProps = {...}`.
- One component per file; file name = component name (`ScoreTag.tsx`).
- Page components in `resources/js/Pages/<Area>/<Page>.tsx`; props type matches the controller's
  Resource and lives in `resources/js/types/pages.ts`.
- No arithmetic on scores in components. The server sends `value`, `display`, `band`, `bandLabel`.
  Allowed client helpers: formatting and heat colour only (`lib/format.ts`, `lib/heat.ts`,
  `lib/months.ts`).
- Filters live in the URL; read via `usePage().props.filters`, change via `router.get(url, q,
  { preserveState: true, preserveScroll: true, replace: true })`.
- Charts: `React.lazy` + `Suspense` with a fixed-size skeleton. Recharts for grouped bars and the
  trend line; hand-built SVG for ranked LGA lists.
- `dangerouslySetInnerHTML` is banned (ESLint `react/no-danger: error`).
- Hooks: `useX` naming, no conditional hooks, effects must clean up.
- State: local `useState`/`useReducer`; no global store library in v1.
- Imports ordered: react/inertia → third-party → `@/` aliases → relative.

---

## 5. Naming

| Thing | Convention | Example |
|---|---|---|
| Domain terms | Exactly as in `ARCHITECTURE.md` §3 | Round, Assessment, Month slot |
| PHP classes | PascalCase, noun or verb-phrase for Actions | `ResolveQuarantine`, `LgaTableAggregator` |
| Validation rules | `<Code>Rule` + `code()` returns SCREAMING_SNAKE | `ScoreRangeRule` → `SCORE_RANGE` |
| Tables | plural snake_case | `quarantined_records` |
| Columns | snake_case; booleans `is_`/`has_`; timestamps `_at` | `is_active`, `closed_at` |
| Dimensions in DB/code | `availability`, `consistency`, `validity` | |
| ODK question names | `(avail|consist|valid)_m[1-3]_<item>` | `consist_m2_opd_register` |
| Routes | kebab-case URLs, dot names | `/admin/data-issues` → `admin.data-issues` |
| Artisan commands | `edqa:<noun>[:<verb>]` | `edqa:admin:create` |
| Config keys | snake_case under `edqa.` | `edqa.soft_rules.visit_min_minutes` |
| Lang keys | `area.page.element` | `dashboard.home.average_score` |
| React components | PascalCase | `FilterBar`, `LgaTile` |
| TS files (non-component) | camelCase | `format.ts` |
| Tests | describe behaviour | `it('quarantines a submission with a null LGA')` |

---

## 6. Design system

Taken from the prototype (`kaduna-edqa-prototype-v4.html`). Tokens are defined once in
`resources/css/app.css`; components use Tailwind utilities referencing them — never hex values
inline.


Define in `resources/css/app.css` with Tailwind 4 `@theme`:

```css
@theme {
  --color-paper: #F1F3EF;   --color-panel: #FFFFFF;
  --color-ink: #17302B;     --color-ink-2: #5A6F68;  --color-ink-3: #8B9C96; /* decorative only — never text */
  --color-rule: #DCE2DC;
  --color-deep: #0D4B44;    --color-deep-2: #12645B; --color-wash: #DCE9E5;
  --color-rail: #0B3D37;
  --color-ok: #2D7753;      --color-ok-bg: #E4F0E7;   /* darkened from prototype #2F7D57 for 4.5:1 */
  --color-warn: #8A6408;    --color-warn-bg: #F6EDD8; /* darkened from prototype #A2760A for 4.5:1 */
  --color-warn-fill: #A2760A;                        /* bars, lines, borders only */
  --color-bad: #9E3B2F;     --color-bad-bg: #F6E2DE;
  --color-pub: #0D4B44;     --color-pub-bg: #DCE9E5;
  --color-priv: #8A5A12;    --color-priv-bg: #F5E9D4;

  --font-sans: "IBM Plex Sans", "Segoe UI", Roboto, system-ui, sans-serif;
  --font-display: "Archivo", "IBM Plex Sans", "Segoe UI", system-ui, sans-serif;

  --shadow-panel: 0 1px 2px rgba(23,48,43,.06), 0 8px 24px -16px rgba(23,48,43,.28);
  --radius-panel: 10px;
}
```

- Fonts **self-hosted** via `@fontsource/archivo` (500/600/700) and `@fontsource/ibm-plex-sans`
  (400/500/600), `font-display: swap`. No Google Fonts CDN (CSP + 3G).
- Base: body 15px / 1.5, `-webkit-font-smoothing: antialiased`, numbers `tabular-nums`.
- Headings use `font-display`, letter-spacing `-0.012em`.
- Focus: `outline: 2px solid deep-2; outline-offset: 2px` — never removed (`ACCESSIBILITY.md` §5).
- Secondary text uses `ink-2` (5.37:1 on white, 4.81:1 on paper). `ink-3` is for dividers and
  decorative marks only.
- Respect `prefers-reduced-motion`.

### Chart palette (from prototype)

| Series | Colour |
|---|---|
| Availability | `#0D4B44` |
| Consistency | `#5A6F68` |
| Validity | `#A2760A` |
| Overall | `#81988F` (prototype `#B7C4BF` fails 3:1 non-text contrast) |
| Target / Strong line | `#A2760A` (`warn-fill`) dashed |

### Heat shading (month cells)

`t = clamp((v − 55) / 45, 0, 1)` → `hsl(146, 28+26t %, 96−30t %)`. **Text is always `ink`** — the
prototype switches to white text above `t > 0.62`, which drops to ~1.5:1 contrast. See `ACCESSIBILITY.md` §4.
Put in `lib/heat.ts`; always show the number in the cell.

### Band colours

Strong/Acceptable → ok, Review → warn, Needs action → bad. Always render the **label** text
(`ScoreTag` shows value; Status column shows label). Colour never carries meaning alone.


### Component inventory
`AppShell`, `Rail`, `SyncStatusBar`, `TopBar`, `FilterBar`, `Tabs`, `Panel`, `SegmentedControl`,
`ScoreTag`, `StatusLabel`, `HeatCell`, `DimensionBar`, `OwnerSplit`, `LgaTile`, `RankedBars`,
`TrendChart`, `MonthGroupChart`, `DataTable`, `Drawer`, `Toast`, `ConfirmWithReasonDialog`,
`EmptyState`, `Skeleton`.

`ConfirmWithReasonDialog` is mandatory for: reject quarantine, override flag, correct assessment,
reopen round, publish rule version, deactivate facility.

---

## 7. Strings and localisation

- Every user-facing string lives in `lang/en/*.php` (grouped by area: `dashboard`, `admin`,
  `validation_rules`, `enums`, `common`).
- Shared to React through an Inertia shared prop (`translations` for the current page's groups)
  and read with a typed `t(key, replacements?)` helper.
- No string concatenation for sentences — use placeholders:
  `'averaged_across' => 'Averaged across :count facilities'`.
- Numbers and dates formatted with `en-NG` locale; dates shown `23 Sep 2026`; times in WAT.
- Validation-rule human text (name + "why it exists") lives in `lang/en/validation_rules.php`,
  keyed by rule code.

---

## 8. Testing

Pest 3 against PostgreSQL (never SQLite).


Pest 3. Postgres for tests too (never SQLite — CHECK constraints, jsonb and materialised views
must behave exactly as in production). Use `RefreshDatabase` against a dedicated `edqa_test` DB,
`--parallel` with per-process databases.

### Layout

```
tests/
  Arch/          arch() rules (layering, no import code, strict types)
  Unit/          ScoreCalculator, Bands, MonthSlot, FieldMap, each ValidationRule
  Feature/
    Database/    constraint tests (write these FIRST — Phase 1 acceptance)
    Ingestion/   OdkClient (Http::fake), pull job, backfill resume, idempotency, edits
    Validation/  pipeline + quarantine workflow
    Scoring/     reference figures, rescore, rule-version publish
    Reporting/   every Aggregator
    Http/        pages render, route guard, filters in URL
    Audit/       every write Action produces the right activity entry
  Fixtures/odk/  JSON submissions (see below)
```

### The broken-submission fixture set (build early)

`tests/Fixtures/odk/broken/` — reproduces the exact defects in the live Looker report. This is
the regression suite for the system's central promise **and** the client demo.

| Fixture | Expected |
|---|---|
| `score_347_66.json` (numeric entry legacy form producing 347.66) | Quarantine `SCORE_RANGE`; direct insert also rejected by DB CHECK (SQLSTATE 23514) |
| `null_lga.json` | Quarantine `LGA_UNKNOWN`; LGA count stays 23 |
| `facility_2019_00.json` | Quarantine `FACILITY_UNKNOWN` |
| `ward_quarterly_start_february.json` | Quarantine `COLUMN_DRIFT` |
| `duplicate_assessment_{a,b}.json` | First accepted, second quarantined `DUPLICATE_ASSESSMENT` |
| `unknown_form_version.json` | Quarantine `UNKNOWN_FORM_VERSION`, no parse attempted |
| `facility_lga_mismatch.json` | `FACILITY_LGA_MISMATCH` |
| `missing_validity_m3.json` | `ITEMS_INCOMPLETE` |
| `end_before_start.json` | `END_BEFORE_START` |
| `outside_window.json` | `ROUND_WINDOW` |
| `all_perfect.json` | Accepted, flagged `ALL_PERFECT` |
| `ten_minute_visit.json` | Accepted, flagged `VISIT_TOO_SHORT` |
| `multiple_failures.json` | Quarantined with **all** failing codes listed |

Plus `tests/Fixtures/odk/valid/` — current form version, an older version with renames, an edit
pair (`deprecatedID`), one with all-N/A slot.

### Must-have tests

- **Constraints:** each CHECK and UNIQUE in ARCHITECTURE.md §4 rejects a bad raw insert.
- **Reference figures:** calculator + aggregators reproduce Public/Private/State figures
  (ARCHITECTURE.md §7, Reference figures).
- **Determinism (G3):** score the same fixture twice → identical `ScoreCard`s.
- **Idempotency:** pulling the same page twice creates no duplicate rows.
- **Resume:** backfill killed after page N restarts at page N (simulate with exception on N+1).
- **Cursor:** `last_pulled_at` does not advance when a page fails mid-way.
- **Lock:** manual pull during a running pull does not double-process.
- **LGA count (G4):** after ingesting all fixtures, every aggregate reports ≤ 23 LGAs.
- **Route guard:** every route except login/2FA/reset/webhook redirects unauthenticated users.
- **2FA:** a user without confirmed 2FA cannot reach any dashboard route.
- **Audit (G7):** each write Action → activity entry with causer, old, new, reason.
- **Webhook:** bad/missing signature → 401; valid → pull job dispatched, no data written.
- **Performance smoke:** seeded 5-year dataset, each dashboard page < 1.5 s (tagged `@slow`).

### Architecture tests

```php
arch('strict types')->expect('App')->toUseStrictTypes();
arch('no permissions package')->expect('Spatie\Permission')->not->toBeUsed();
arch('reporting never reads quarantine')
    ->expect('App\Domain\Reporting')
    ->not->toUse(['App\Domain\Validation\Models\QuarantinedRecord', 'App\Domain\Ingestion\Models\Submission']);
arch('controllers are thin')->expect('App\Http\Controllers')->not->toUse('Illuminate\Support\Facades\DB');
arch('no import namespace')->expect('App\Domain\Ingestion\Csv')->toBeEmpty(); // or assert dir absent
arch('no debug')->expect(['dd', 'dump', 'ray', 'var_dump'])->not->toBeUsed();
```

### Frontend

- `tsc --noEmit` in CI; ESLint with `@typescript-eslint/no-explicit-any` = error and
  `react/no-danger` = error.
- Vitest for `lib/` helpers (heat, format, months).
- Optional: Playwright smoke across the six pages at 360px and 1280px.

### Seed data

`DatabaseSeeder` (local/staging only): 23 LGAs, ~10 wards each, ~2,000 public + ~219 private
facilities, four rounds, synthetic item responses tuned so state means approximate the published
distributions (Public ≈ 92/79/92, Private ≈ 75/62/84). The prototype's `buildSample()` is a good
reference for per-LGA counts and noise.

---

## 9. Git, branches and reviews

- Trunk-based: short-lived branches off `main`: `feat/<area>-<short>`, `fix/...`, `chore/...`.
- **Conventional Commits**: `feat(scoring): add N/A exclusion`, `fix(ingestion): hold cursor on
  page failure`, `test(validation): add COLUMN_DRIFT fixture`. Scopes: `ingestion`, `validation`,
  `scoring`, `reporting`, `dashboard`, `admin`, `auth`, `db`, `export`, `plan`, `infra`, `docs`.
- One logical change per commit. Tests in the same commit as the code they cover.
- PR description: what, why, how tested, screenshots for UI at 360px and 1280px, any new
  decision added to `ARCHITECTURE.md` §11.
- CI must be green: Pint (test mode), Larastan L6, Pest, `tsc --noEmit`, ESLint, Vitest,
  `composer audit`, `npm audit`.
- Squash-merge; the squash message is a Conventional Commit.

---

## 10. Definition of done (per feature)

- [ ] Pest: happy path + at least two failure paths
- [ ] Larastan level 6 clean, Pint formatted, `tsc` and ESLint clean
- [ ] No N+1 queries (lazy-loading prevention on, verified with Debugbar/Pulse)
- [ ] Every write produces an activity-log entry — **asserted by test**
- [ ] All strings in language files
- [ ] Works at 360px; tables scroll horizontally
- [ ] Meets `ACCESSIBILITY.md` checklist for any UI touched
- [ ] No aggregate computed outside an Aggregator
- [ ] Docs updated if behaviour or a decision changed
- [ ] Runs in the containers: `make test` green; if env keys, queues, schedule, volumes or
      grants changed, the `DEPLOY.md` §1.3 contract is updated and `edqa-infra` notified

---

## 11. Tooling configuration

**`pint.json`**
```json
{ "preset": "laravel", "rules": { "declare_strict_types": true, "final_class": true,
  "ordered_imports": { "sort_algorithm": "alpha" }, "strict_comparison": true } }
```

**`phpstan.neon`**
```neon
includes:
  - vendor/larastan/larastan/extension.neon
parameters:
  level: 6
  paths: [app, config, database, routes]
```

**`tsconfig.json`** (excerpt)
```json
{ "compilerOptions": { "strict": true, "noUncheckedIndexedAccess": true,
  "exactOptionalPropertyTypes": true, "noImplicitOverride": true,
  "paths": { "@/*": ["./resources/js/*"] } } }
```

**ESLint** — `@typescript-eslint/strict`, `react-hooks`, `jsx-a11y/strict`,
rules: `no-explicit-any: error`, `react/no-danger: error`, `no-console: warn`.

**Composer scripts**
```json
"scripts": {
  "lint": ["pint --test", "phpstan analyse --memory-limit=1G"],
  "test": "pest --parallel",
  "check": ["@lint", "@test"]
}
```

---

## 12. Containers and Docker

The app runs as containers in every environment (`ARCHITECTURE.md` §2, `DEPLOY.md` §6–7).
Code must behave well in them.

### 12.1 Rules for application code
- **Stateless processes.** Nothing written to the local filesystem survives a restart except
  `storage/app` (a shared volume). Never store anything the app needs later in
  `storage/framework`, `storage/logs`, `/tmp` or `bootstrap/cache` — those are tmpfs.
- **Persistent files** (ODK attachments, exports) go through a named disk rooted at
  `storage/app` (`odk`, `exports`), never the `public` disk.
- **Logs to stderr** (`LOG_CHANNEL=stderr`, JSON formatter in staging/production). No log files.
- **Config is cached at container start, not at build.** Never call `env()` outside
  `config/*.php`; never run `config:cache` in the Dockerfile.
- **Migrations never run from the web or worker role.** Only the one-off `migrate` role, as the
  migrator DB user, followed by `database/sql/post-migrate-grants.sql`.
- **Graceful shutdown.** Workers receive SIGTERM on every deploy; jobs must be idempotent and
  either finish within their `timeout` or be safe to retry. Long jobs (rescore) are chunked and
  report progress so a restart resumes rather than repeats.
- **Hosts are service names**: `DB_HOST=postgres`, `REDIS_HOST=redis`. Never `localhost` or
  `127.0.0.1` in `.env.example`.
- **The scheduler runs once.** Keep `withoutOverlapping()` on the ODK pull; don't rely on
  `onOneServer()`.
- **Browsershot** renders only the app's own Blade views, never external URLs, always with
  `noSandbox()` and the Chromium path from env.
- **Health:** keep Laravel's `/up` route; FPM `ping.path=/ping` stays in `fpm-pool.conf`.

### 12.2 Dockerfile conventions
- One multi-stage `Dockerfile`; targets `base`, `vendor`, `assets`, `app`, `worker`, `web`,
  `ci`, `dev`. Don't add a second Dockerfile.
- Base images pinned by digest; Renovate updates them.
- `hadolint` clean. `COPY` specific paths; keep `.dockerignore` strict (`.git`, `node_modules`,
  `vendor`, `.env*`, `storage/*`, `discovery/`).
- Order layers for cache hits: lock files → install → source.
- Final runtime stages run as `www-data`; no `sudo`, no compilers, no Composer, no dev deps.
- No secrets in `ARG`/`ENV`/`COPY`. Build args are visible in image history.
- The `app` image must not contain Chromium; only `worker` does.

### 12.3 Compose conventions (`compose.dev.yml`)
- Service names match production: `app`, `web`, `worker`, `scheduler`, `postgres`, `redis`.
- Only dev publishes Postgres/Mailpit/Vite ports; production publishes only `web`.
- The scheduler is opt-in locally (`--profile scheduler`) so dev machines don't hit ODK every
  10 minutes.

### 12.4 Everyday commands
```bash
make up                     # docker compose -f compose.dev.yml up -d
make sh                     # shell in the app container
make test                   # pest --parallel against edqa_test inside the container
make lint                   # pint --test + phpstan inside the container
make artisan c="migrate:fresh --seed"
make logs s=worker
docker build --target app -t edqa-app:local .    # build the production image locally
```