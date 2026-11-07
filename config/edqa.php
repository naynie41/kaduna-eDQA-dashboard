<?php

declare(strict_types=1);

use App\Support\Config\EnvFlag;
use App\Support\Config\EnvList;
use App\Support\Config\EnvNumber;

return [

    'auth' => [
        // SECURITY.md §2: no MFA (D-35), so these compensating controls are mandatory.

        // Optional allow-list (CIDRs or addresses, comma-separated). When set, sign-in, password
        // reset and every signed-in page answer 404 to any other address. Empty = off.
        'admin_allowed_ips' => EnvList::csv(env('EDQA_ADMIN_ALLOWED_IPS')),

        // Progressive lockout per account: after this many failed sign-ins, locked for these
        // minutes; null = until `edqa:admin:unlock`. Attempts while locked do not count.
        'lockout' => [
            ['failures' => 5, 'minutes' => 15],
            ['failures' => 10, 'minutes' => 60],
            ['failures' => 20, 'minutes' => null],
        ],

        // A session ends this long after sign-in, however active (the idle limit is SESSION_LIFETIME).
        'absolute_session_hours' => 12,

        // A sign-in from an IP + browser pair not seen for this long emails the administrators.
        'new_device_days' => 90,

        // A new password may not be any of the last this many (the current one included).
        'password_history' => 5,
    ],

    'public' => [
        // SECURITY.md §8, D-82: noindex everywhere until the client decides. When true, only the public
        // dashboard pages drop the X-Robots-Tag and the robots meta tag; /admin and /login never do.
        'indexable' => false,

        // throttle:public, per IP, for the public pages (and the public JSON, Phase P step 3).
        'per_minute' => 120,

        // public.cache: how long a visitor's browser may keep a public page or public JSON, in seconds
        // (ARCHITECTURE.md §10). The figures change only at a round close or rescore.
        'max_age' => 60,

        // D-77: the public sees closed rounds only (PublicScope). true also shows the open round
        // publicly, figures that may still move while data issues are resolved. Default false.
        'show_open_round' => false,
    ],

    'aggregation' => [
        // ARCHITECTURE.md §7, D-04: 'unweighted' = every facility counts once in a mean, up to
        // LGA, owner and state. 'facility_count' has no agreed meaning yet (Q-27) and is refused.
        'weighting' => 'unweighted',

        // Read round_aggregates when a filter maps onto its rows (one round, a scope, an owner
        // type, a level), else compute with SQL. The view is reconciled with that SQL to 1e-9
        // (RoundAggregatesReconciliationTest) and is ignored until first populated.
        'read_view' => true,
    ],

    'status' => [
        // ARCHITECTURE.md §9.1: the sync status bar turns amber when the last successful ODK
        // pull is older than this.
        'stale_after_minutes' => 30,

        // SECURITY.md §12: no successful pull for this long while a round is open puts a banner on
        // every admin page.
        'banner_after_hours' => 24,
    ],

    'rounds' => [
        // ARCHITECTURE.md §3: a round's window lies inside its quarter, but may end up to this many
        // days after the quarter for late visits (allowed with a warning). Windows never overlap.
        'window_grace_days' => 45,
    ],

    'rescore' => [
        // ARCHITECTURE.md §7: assessments per RescoreRoundJob in the batch (one transaction each).
        'chunk_size' => 200,

        // Automatic triggers (publish, quarantine resolved, correction) wait this long and
        // collapse into one rescore per round (RequestRescoreJob).
        'debounce_seconds' => 30,
    ],

    // The field-collection sources (ARCHITECTURE.md §5.0, SECURITY.md §6): what each one is, where
    // it is, how to sign in and which form it holds, all from env (never stored, logged or sent to
    // the browser). A source with any credential missing is "not configured" and never pulls;
    // enabled=false (ODK_ENABLED / KOBO_ENABLED=false) switches a configured one off.
    // edqa:sources:sync keeps submission_sources in step with this list (it never deletes rows).
    'sources' => [
        'odk' => [
            'type' => 'odk_central',
            'name' => 'ODK Central',
            'enabled' => EnvFlag::enabledUnlessFalse(env('ODK_ENABLED')),
            // The service account is a Project Viewer. The links to a submission use url and
            // project_id too.
            'url' => env('ODK_CENTRAL_URL') ?: null,
            'email' => env('ODK_CENTRAL_EMAIL') ?: null,
            'password' => env('ODK_CENTRAL_PASSWORD') ?: null,
            'project_id' => env('ODK_PROJECT_ID') ?: null,
            'form_id' => env('ODK_FORM_ID') ?: null,
            // Submissions per OData page (§5.3).
            'page_size' => EnvNumber::positiveInt(env('ODK_PULL_PAGE_SIZE'), 500),
            // The backfill's wait between pages (§5.6, D-53): empty or unset means 5; 0 turns it off.
            'backfill_throttle_seconds' => EnvNumber::positiveInt(env('ODK_BACKFILL_THROTTLE'), 5),
        ],
        'kobo' => [
            'type' => 'kobo',
            'name' => 'KoboToolbox',
            'enabled' => EnvFlag::enabledUnlessFalse(env('KOBO_ENABLED')),
            // The global, EU or a self-hosted server (ARCHITECTURE.md §5.3). The token
            // belongs to a dedicated read-only account (SECURITY.md §6).
            'url' => env('KOBO_BASE_URL') ?: null,
            'token' => env('KOBO_API_TOKEN') ?: null,
            'asset_uid' => env('KOBO_ASSET_UID') ?: null,
            'page_size' => EnvNumber::positiveInt(env('KOBO_PULL_PAGE_SIZE'), 500),
            'backfill_throttle_seconds' => EnvNumber::positiveInt(env('KOBO_BACKFILL_THROTTLE'), 5),
        ],
    ],

    'kobo' => [
        // How the KoboToolbox client talks to the server (ARCHITECTURE.md §5.3, SECURITY.md §6);
        // the source itself (URL, token, asset, page size) is under sources.kobo.

        // Seconds: connect, and the whole request (one page).
        'timeouts' => ['connect' => 10, 'total' => 60],

        // Per request: 3 attempts on 429, 5xx and connection errors, waiting base × 2^n ms (or
        // the server's Retry-After), at most max_ms. No retry on any other 4xx; a 401 or 403 stops
        // the source until the token is fixed.
        'retry' => ['attempts' => 3, 'base_ms' => 1000, 'max_ms' => 30000],

        // Local development only: lets KOBO_BASE_URL be plain http, for a mock server. Honoured only
        // when APP_ENV=local; set elsewhere it is refused and Kobo counts as not configured.
        'allow_insecure_local' => filter_var(env('KOBO_ALLOW_INSECURE_LOCAL', false), FILTER_VALIDATE_BOOL),

        // Where edqa:source-mock:generate kobo writes the mock's data (the kobo-mock service reads it).
        'mock_path' => storage_path('app/kobo-mock'),

        // The webhook (§5.4, SECURITY.md §6): Kobo's REST Services cannot sign, so the service sends
        // this token in X-EDQA-Webhook-Token (compared with hash_equals). Off (404) while unset or
        // shorter than 32 characters; it only queues a pull, at most one per debounce_seconds.
        'webhook' => [
            'token' => env('KOBO_WEBHOOK_TOKEN') ?: null,
            'debounce_seconds' => 60,
        ],
    ],

    // Pulling from any source (ARCHITECTURE.md §5.3). One pull per source at a time under
    // Cache::lock("pull:{source}"), held at most lock_seconds, so ODK and Kobo pull in parallel but
    // never twice each; a second pull of a source waits up to lock_wait_seconds, then goes back on
    // the queue. The reconcile sweep takes the same lock. reconcile_at: the nightly sweep, per
    // source, for the open round.
    'pull' => ['lock_seconds' => 900, 'lock_wait_seconds' => 600, 'reconcile_at' => '02:30'],

    // The historical backfill (§5.6, D-53), one source at a time: how long one
    // BackfillSubmissionsJob runs before it queues the next from its checkpoint (the wait between
    // pages is per source, sources.*.backfill_throttle_seconds); and how long an open backfill run
    // of another source, untouched, still counts as running (a crash leaves one open).
    'backfill' => [
        'job_seconds' => 300,
        'stale_after_seconds' => 3600,
    ],

    'odk' => [
        // How the ODK Central adapter talks to Central (ARCHITECTURE.md §5, SECURITY.md §6); the
        // source itself (URL, credentials, form, page size) is under sources.odk.

        // Seconds: connect, and the whole request (one page).
        'timeouts' => ['connect' => 10, 'total' => 60],

        // Per request: 3 attempts on 429, 5xx and connection errors, waiting base × 2^n ms (or
        // the server's Retry-After), at most max_ms. No retry on any other 4xx.
        'retry' => ['attempts' => 3, 'base_ms' => 1000, 'max_ms' => 30000],

        // The session token is refreshed this many minutes before it expires.
        'token_refresh_minutes' => 30,

        // Local development only: lets ODK_CENTRAL_URL be plain http, for the mock server
        // (docker/odk-mock, `make odk-mock-up`). Honoured only when APP_ENV=local; set in any other
        // environment it is refused and ODK counts as not configured (SECURITY.md §6: https only).
        'allow_insecure_local' => filter_var(env('ODK_ALLOW_INSECURE_LOCAL', false), FILTER_VALIDATE_BOOL),

        // Where edqa:odk-mock:generate writes the mock's data (the odk-mock service reads it).
        'mock_path' => storage_path('app/odk-mock'),

        // The webhook (§5.4, SECURITY.md §6, D-54): off (404) until ODK_WEBHOOK_SECRET is set. A request
        // is signed HMAC-SHA256 over "{timestamp}.{raw body}" and its timestamp must be within
        // tolerance_seconds; it only queues a pull, at most one per debounce_seconds.
        'webhook' => [
            'secret' => env('ODK_WEBHOOK_SECRET') ?: null,
            'tolerance_seconds' => 300,
            'debounce_seconds' => 60,
        ],

        // Attachments (§5.3, SECURITY.md §7): fetched on the low queue to the private attachments disk. A
        // file over max_bytes, or whose sniffed type is not in allowed_mimes, is rejected and
        // deleted.
        'attachments' => [
            // storage/app/attachments/{source}/{instance}/ (ARCHITECTURE.md §5.3); the old odk disk is
            // read only by edqa:attachments:relocate.
            'disk' => 'attachments',
            'max_bytes' => 10 * 1024 * 1024,
            'allowed_mimes' => ['image/jpeg', 'image/png', 'application/geo+json', 'text/csv', 'application/xml'],
        ],
    ],

    // Where the project's documents (runbooks) are read, for links from the admin pages; null: no
    // link, the page names the file instead.
    'docs' => [
        'base_url' => 'https://github.com/naynie41/kaduna-eDQA-dashboard/blob/main',
    ],

    'heartbeats' => [
        // Pinged (GET) after each successful pull of that source, for an external dead-man's switch;
        // unset: skipped. HEARTBEAT_ODK_PULL_URL is the old name, read for one more release.
        'pull' => [
            'odk' => env('HEARTBEAT_PULL_ODK_URL') ?: env('HEARTBEAT_ODK_PULL_URL') ?: null,
            'kobo' => env('HEARTBEAT_PULL_KOBO_URL') ?: null,
        ],
    ],

    'plan' => [
        // ARCHITECTURE.md §9.2.6: an LGA whose overall is below this band's lower bound (read
        // from the rule version, on the displayed value as bands are) gets an action; at or
        // above it, "No action needed". strong | acceptable | review.
        'action_below' => 'acceptable',

        // The default owner of each dimension's standard action: a key of lang/en/plan.php
        // owners. Phase 6 makes the owner editable per row (plan_actions).
        // D-79: who owns a plan action is a role (a unit), never a person: plan_actions.owner_role is
        // one of these (a CHECK holds the same list), labelled in lang/en/plan.php owners. A personal
        // contact, if any, is plan_actions.owner_contact: admin-only, never on the public plan.
        'roles' => ['lga_me_officer', 'state_me_team', 'programme_manager'],

        'default_owners' => [
            'availability' => 'lga_me_officer',
            'consistency' => 'state_me_team',
            'validity' => 'programme_manager',
        ],
    ],

    'presentation' => [
        // Q-09: how a score is rounded for display, and therefore which band it shows (Q-26):
        // 'half_up' (default) or 'truncate'. The live tool shows the private overall 73.67 as
        // 73, which only truncation gives. App\Domain\Scoring\Presentation reads this.
        'rounding' => 'half_up',
    ],

    'validation' => [
        // ARCHITECTURE.md §6, in catalogue order: the pipeline runs them in this order and
        // UNKNOWN_FORM_VERSION must come first. Strings, not ::class: the rule classes arrive
        // in step 2B, one per line here (CONVENTION.md §5: <Code>Rule).
        'rules' => [
            'UNKNOWN_FORM_VERSION' => 'App\Domain\Validation\Rules\UnknownFormVersionRule',
            'COLUMN_DRIFT' => 'App\Domain\Validation\Rules\ColumnDriftRule',
            'LGA_UNKNOWN' => 'App\Domain\Validation\Rules\LgaUnknownRule',
            'FACILITY_UNKNOWN' => 'App\Domain\Validation\Rules\FacilityUnknownRule',
            'FACILITY_LGA_MISMATCH' => 'App\Domain\Validation\Rules\FacilityLgaMismatchRule',
            'ITEMS_INCOMPLETE' => 'App\Domain\Validation\Rules\ItemsIncompleteRule',
            'SCORE_RANGE' => 'App\Domain\Validation\Rules\ScoreRangeRule',
            'END_BEFORE_START' => 'App\Domain\Validation\Rules\EndBeforeStartRule',
            'ROUND_WINDOW' => 'App\Domain\Validation\Rules\RoundWindowRule',
            'DUPLICATE_ASSESSMENT' => 'App\Domain\Validation\Rules\DuplicateAssessmentRule',
            'SCORE_JUMP' => 'App\Domain\Validation\Rules\ScoreJumpRule',
            'ALL_PERFECT' => 'App\Domain\Validation\Rules\AllPerfectRule',
            'VISIT_TOO_SHORT' => 'App\Domain\Validation\Rules\VisitTooShortRule',
            'ASSESSOR_VOLUME' => 'App\Domain\Validation\Rules\AssessorVolumeRule',
        ],

        // Soft rules flag an accepted assessment; they never quarantine.
        'soft_rules' => [
            'score_jump_points' => 30,     // SCORE_JUMP: overall moved more than this since last round
            'visit_min_minutes' => 20,     // VISIT_TOO_SHORT
            'assessor_max_per_day' => 6,   // ASSESSOR_VOLUME: facilities per assessor per day
        ],

        // COLUMN_DRIFT: values that land in the ward column when ODK columns shift
        // (live report: Ward = "Quarterly"). Compared lower-case; numeric wards are caught too.
        'reserved_ward_words' => [
            'quarterly', 'monthly', 'annual', 'null', 'n/a', 'none',
            'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august',
            'september', 'october', 'november', 'december',
        ],
    ],

];
