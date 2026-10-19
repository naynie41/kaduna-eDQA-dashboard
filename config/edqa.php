<?php

declare(strict_types=1);

use App\Support\Config\EnvFlag;

return [

    'auth' => [
        // SECURITY.md §2 / D-26: 2FA is required unless EDQA_REQUIRE_2FA is explicitly false.
        // When off, an account that has set up 2FA is still challenged at sign-in.
        'require_two_factor' => EnvFlag::enabledUnlessFalse(env('EDQA_REQUIRE_2FA')),
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

    'odk' => [
        // ODK Central (ARCHITECTURE.md §5, SECURITY.md §6), read only here: the client never reads
        // env. Any of the five missing (or a URL that is not https) means "not configured": every
        // caller says so plainly instead of failing. The service account is a Project Viewer.
        // The facility drawer's link to a submission uses url and project_id too.
        'url' => env('ODK_CENTRAL_URL') ?: null,
        'email' => env('ODK_CENTRAL_EMAIL') ?: null,
        'password' => env('ODK_CENTRAL_PASSWORD') ?: null,
        'project_id' => env('ODK_PROJECT_ID') ?: null,
        'form_id' => env('ODK_FORM_ID') ?: null,

        // Submissions per OData page (§5.3).
        'page_size' => (int) (env('ODK_PULL_PAGE_SIZE') ?: 500),

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

        // The pull (§5.3): one at a time under Cache::lock('odk-pull'), held at most lock_seconds;
        // a second pull waits up to lock_wait_seconds for it, then goes back on the queue.
        'pull' => ['lock_seconds' => 900, 'lock_wait_seconds' => 600],

        // Attachments (§5.3, SECURITY.md §7): fetched on the low queue to the private odk disk. A
        // file over max_bytes, or whose sniffed type is not in allowed_mimes, is rejected and
        // deleted.
        'attachments' => [
            'disk' => 'odk',
            'max_bytes' => 10 * 1024 * 1024,
            'allowed_mimes' => ['image/jpeg', 'image/png', 'application/geo+json', 'text/csv', 'application/xml'],
        ],
    ],

    'heartbeats' => [
        // Pinged (GET) after each successful pull, for an external dead-man's switch; unset: skipped.
        'odk_pull' => env('HEARTBEAT_ODK_PULL_URL') ?: null,
    ],

    'plan' => [
        // ARCHITECTURE.md §9.2.6: an LGA whose overall is below this band's lower bound (read
        // from the rule version, on the displayed value as bands are) gets an action; at or
        // above it, "No action needed". strong | acceptable | review.
        'action_below' => 'acceptable',

        // The default owner of each dimension's standard action: a key of lang/en/plan.php
        // owners. Phase 6 makes the owner editable per row (plan_actions).
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
