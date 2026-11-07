<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Database\Seeders\LgaSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// SECURITY.md §3.1, CONVENTION.md §8 (D-83): the leak test. Everything private is seeded with a
// marker. An anonymous visitor then requests every public route and public API endpoint (found in
// the router, so a new public route is covered without touching this file) with ten filter
// combinations, every deferred prop of every page, and the facility drawer for 20 facilities. Any
// forbidden key, marker, instance ID, open-round figure or admin email in any of it fails.

/** Strings that exist only in private data. */
const MARKERS = [
    'MARKER-ASSESSOR', 'MARKER-DEVICE', 'MARKER-ATTACH', 'MARKER-QUARANTINE', 'MARKER-DUPLICATE',
    'MARKER-REASON', 'MARKER-ADMIN', 'MARKER-CONTACT', 'MARKER-EDIT', 'MARKER-OPENONLY',
    // Facility master-list coordinates (Q-20: off by default).
    '10.98765', '7.65432',
];

/** Keys that must never appear in public output (SECURITY.md §3.1), at any depth outside the UI strings. */
const FORBIDDEN_KEYS = [
    'assessor', 'assessor_name', 'device_id', 'submitter', 'instance_id', 'external_id', 'submission_id',
    'form_version', 'form_ref', 'payload', 'attachments', 'flags', 'activity', 'source', 'source_url',
    'quarantine', 'quarantined', 'failures', 'duplicate_of', 'deletion', 'gps', 'lat', 'lng', 'email',
    'owner_contact', 'reason', 'causer', 'corrected', 'shell', 'admin',
];

/** The open round's year and its scores (13.57 everywhere): neither may show publicly (D-77). */
const OPEN_YEAR = '2031';
const OPEN_FIGURES = ['13.57', '13.6'];

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $version = Rows::ruleVersion(['config' => json_encode(ReportingFixture::CONFIG)]);
    $this->closed = Rows::round(['year' => 2025, 'quarter' => 4, 'window_start' => '2025-10-01', 'window_end' => '2025-12-31', 'status' => 'closed', 'closed_at' => now()->subMonth(), 'rule_version_id' => $version]);
    $this->open = Rows::round(['year' => 2031, 'quarter' => 1, 'window_start' => '2031-01-01', 'window_end' => '2031-03-31', 'status' => 'open']);
    $lgas = DB::table('lgas')->orderBy('code')->limit(3)->pluck('id', 'code')->all();
    $this->lgaCodes = array_keys($lgas);

    $this->facilities = [];
    foreach (range(1, 22) as $i) {
        $lgaId = array_values($lgas)[$i % 3];
        $facility = Rows::facility([
            'name' => "Clinic {$i}", 'code' => "KD-LEAK-{$i}", 'lga_id' => $lgaId,
            'ward_id' => Rows::ward(['lga_id' => $lgaId]), 'ownership' => $i % 4 === 0 ? 'private' : 'public',
            'level' => ['primary', 'secondary', 'tertiary'][$i % 3], 'lat' => 10.98765, 'lng' => 7.65432,
        ]);
        $this->facilities[] = $facility;

        // The closed round: published figures, with private details around them.
        $closed = Rows::assessment([
            'round_id' => $this->closed, 'facility_id' => $facility,
            'assessor_name' => "MARKER-ASSESSOR-Zeb {$i}", 'device_id' => "MARKER-DEVICE-{$i}",
            'started_at' => '2025-11-04 09:00:00+01', 'ended_at' => '2025-11-04 10:00:00+01',
            'status' => $i % 5 === 0 ? 'flagged' : 'accepted', 'flags' => json_encode($i % 5 === 0 ? ['VISIT_TOO_SHORT'] : []),
        ]);
        ReportingFixture::scores($closed, $version, [60 + $i, 70, 80], [65, 75 + $i % 7, 85], [70, 80, 90 - $i % 9]);
        Rows::itemResponse(['assessment_id' => $closed, 'dimension' => 'availability', 'month_slot' => 1, 'item_code' => 'item_1', 'value' => 'yes']);
        Rows::itemResponse(['assessment_id' => $closed, 'dimension' => 'validity', 'month_slot' => 2, 'item_code' => 'item_2', 'value' => 'no']);
        DB::table('submission_attachments')->insert(['submission_id' => Assessment::query()->whereKey($closed)->value('submission_id'), 'filename' => "MARKER-ATTACH-{$i}.jpg", 'status' => 'fetched', 'mime' => 'image/jpeg', 'size' => 1200, 'path' => "x/MARKER-ATTACH-{$i}.jpg"]);

        // The open round: figures the public must never see (13.57 everywhere).
        if ($i <= 6) {
            $open = Rows::assessment(['round_id' => $this->open, 'facility_id' => $facility, 'assessor_name' => 'MARKER-ASSESSOR-open', 'started_at' => '2031-02-02 09:00:00+01', 'ended_at' => '2031-02-02 10:00:00+01']);
            ReportingFixture::scores($open, $version, [13.57, 13.57, 13.57], [13.57, 13.57, 13.57], [13.57, 13.57, 13.57]);
        }
    }
    // A facility assessed only in the open round.
    $openOnly = Rows::facility(['name' => 'MARKER-OPENONLY Clinic', 'lga_id' => array_values($lgas)[0], 'ward_id' => Rows::ward(['lga_id' => array_values($lgas)[0]])]);
    $openAssessment = Rows::assessment(['round_id' => $this->open, 'facility_id' => $openOnly, 'started_at' => '2031-02-03 09:00:00+01', 'ended_at' => '2031-02-03 10:00:00+01']);
    ReportingFixture::scores($openAssessment, $version, [13.57, 13.57, 13.57], [13.57, 13.57, 13.57], [13.57, 13.57, 13.57]);
    $this->facilities[] = $openOnly;

    // An edit at source: the original superseded, its instance ID deprecated.
    Rows::submission(['instance_id' => 'uuid:5e1f0c2a-0000-4000-8000-000000000001', 'deprecated_id' => 'uuid:5e1f0c2a-0000-4000-8000-000000000002', 'status' => 'superseded', 'payload' => json_encode(['note' => 'MARKER-EDIT'])]);
    // Quarantine, and a duplicate of an accepted assessment (from the other source).
    Rows::quarantined(['payload' => json_encode(['note' => 'MARKER-QUARANTINE']), 'failures' => json_encode([['code' => 'LGA_UNKNOWN', 'severity' => 'hard', 'detail' => 'MARKER-QUARANTINE lga blank']])]);
    Rows::quarantined(['failures' => json_encode([['code' => 'DUPLICATE_ASSESSMENT', 'severity' => 'hard', 'detail' => 'MARKER-DUPLICATE of an accepted visit']])]);
    Rows::pullRun(['cross_source_duplicates' => 1, 'outcome' => 'succeeded', 'finished_at' => now()]);

    // An administrator, their audit trail, and a plan action with a personal contact.
    $admin = User::factory()->create(['name' => 'MARKER-ADMIN-Amina', 'email' => 'marker-admin@edqa.test']);
    $this->adminEmail = $admin->email;
    AuditLog::record(Assessment::query()->findOrFail($openAssessment), $admin, 'corrected', ['times' => 'a'], ['times' => 'b'], 'MARKER-REASON supervisor re-checked');
    Rows::planAction(['round_id' => $this->closed, 'lga_id' => array_values($lgas)[1], 'owner_role' => 'state_me_team', 'owner_contact' => 'MARKER-CONTACT 0803 000 0000']);
    auth()->logout();
});

/** Ten filter combinations: none, closed and open rounds, each filter, mixtures and junk. */
function leakFilters(array $lgaCodes, int $openRoundId): array
{
    return [
        [],
        ['year' => '2025'],
        ['year' => '2025', 'quarter' => '4'],
        ['year' => OPEN_YEAR, 'quarter' => '1'],
        ['year' => '2025', 'month' => '11'],
        ['lga' => $lgaCodes[0]],
        ['level' => 'secondary', 'owner' => 'private'],
        ['year' => '2025', 'quarter' => '4', 'lga' => $lgaCodes[1], 'owner' => 'public', 'metric' => 'validity', 'sort' => 'ward', 'dir' => 'desc'],
        ['year' => OPEN_YEAR, 'round' => (string) $openRoundId, 'owner_view' => 'private'],
        ['year' => 'x', 'quarter' => '9', 'lga' => '<script>', 'page' => '999'],
    ];
}

/** Every public GET route as a concrete URL (the drawer's {facility} is covered separately). @return list<string> */
function publicUrls(): array
{
    $urls = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (! str_starts_with((string) $route->getName(), 'public.') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $uri = '/'.ltrim($route->uri(), '/');
        if (str_contains($uri, '{facility}')) {
            continue;
        }
        if (str_contains($uri, '{page}')) {
            foreach (['home', 'facility', 'lga', 'monitor', 'visuals', 'plan'] as $page) {
                $urls[] = str_replace('{page}', $page, $uri);
            }

            continue;
        }
        $urls[] = $uri;
    }

    return array_values(array_unique($urls));
}

/** Every key at any depth, outside the UI strings (whose keys are labels, not data). @return list<string> */
function leakKeys(mixed $value, string $path = ''): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $inner) {
        if ($key === 'translations') {
            continue;
        }
        if (is_string($key)) {
            $keys[] = $key;
        }
        array_push($keys, ...leakKeys($inner, "{$path}.{$key}"));
    }

    return $keys;
}

/** A redirect only echoes the visitor's own query (the open year they typed), never data: $redirect skips that check. */
function assertNothingLeaks(string $label, string $body, mixed $data, string $adminEmail, bool $redirect = false): void
{
    foreach (MARKERS as $marker) {
        expect(str_contains($body, $marker))->toBeFalse("{$label} contains the marker {$marker}");
    }
    expect(preg_match('/uuid:[A-Za-z0-9-]{4,}/', $body))->toBe(0, "{$label} contains an instance ID")
        ->and(str_contains($body, $adminEmail))->toBeFalse("{$label} contains an admin email")
        ->and(preg_match('/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/', $body))->toBe(0, "{$label} contains an email address")
        ->and(! $redirect && str_contains($body, OPEN_YEAR))->toBeFalse("{$label} names the open round");
    foreach (OPEN_FIGURES as $figure) {
        expect(str_contains($body, $figure))->toBeFalse("{$label} contains the open-round figure {$figure}");
    }
    $found = array_values(array_intersect(array_unique(leakKeys($data)), FORBIDDEN_KEYS));
    expect($found)->toBe([], "{$label} has forbidden keys");
}

/** The page's own JSON (props and all), or the JSON body; null when neither (a redirect). */
function leakData(TestResponse $response): mixed
{
    if ($response->headers->get('X-Inertia') === 'true' || str_contains((string) $response->headers->get('Content-Type'), 'json')) {
        return $response->json();
    }

    return leakPage($response);
}

/** The Inertia page of a full page response (a view); null for JSON, redirects and the rest. */
function leakPage(TestResponse $response): ?array
{
    $original = $response->baseResponse->getStatusCode() === 200 && method_exists($response->baseResponse, 'getOriginalContent') ? $response->baseResponse->getOriginalContent() : null;

    return $original instanceof View ? $response->viewData('page') : null;
}

it('leaks nothing private to an anonymous visitor, on any public route, filter, deferred prop or drawer', function (): void {
    $urls = publicUrls();
    expect($urls)->toContain('/')->toContain('/facilities')->toContain('/lgas')->toContain('/monitor')->toContain('/visuals')->toContain('/plan')
        ->toContain('/api/public/aggregates')->toContain('/api/public/facilities')->toContain('/api/public/status');

    $checked = 0;
    foreach ($urls as $url) {
        foreach (leakFilters($this->lgaCodes, $this->open) as $filters) {
            $full = $url.($filters === [] ? '' : '?'.http_build_query($filters));
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($checked % 200 + 1)])->get($full);
            expect($response->getStatusCode())->toBeIn([200, 301, 302], "{$full} answered {$response->getStatusCode()}");
            $this->assertGuest();
            assertNothingLeaks($full, (string) $response->getContent(), leakData($response), $this->adminEmail, $response->isRedirect());
            $checked++;

            // Every deferred prop group of an Inertia page.
            $page = leakPage($response);
            foreach (is_array($page) ? ($page['deferredProps'] ?? []) : [] as $group => $props) {
                $partial = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($checked % 200 + 1)])->get($full, [
                    'X-Inertia' => 'true',
                    'X-Inertia-Version' => (string) ($page['version'] ?? ''),
                    'X-Inertia-Partial-Component' => (string) $page['component'],
                    'X-Inertia-Partial-Data' => implode(',', $props),
                ]);
                expect($partial->getStatusCode())->toBe(200, "{$full} deferred {$group}");
                assertNothingLeaks("{$full} (deferred {$group})", (string) $partial->getContent(), $partial->json(), $this->adminEmail);
                $checked++;
            }
        }
    }

    // The public drawer, for 20 facilities (and the open-round-only one), asked for the open round too.
    foreach (array_merge(array_slice($this->facilities, 0, 20), [end($this->facilities)]) as $facility) {
        foreach (['', "?round={$this->open}", '?year='.OPEN_YEAR.'&quarter=1'] as $query) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($checked % 200 + 1)])->getJson("/api/public/facilities/{$facility}{$query}");
            expect($response->getStatusCode())->toBe(200);
            $body = (string) $response->getContent();
            if ($facility === end($this->facilities)) {
                // Its name is master-list data, public; its open-round figures are not.
                expect($response->json('data.round.label'))->toBe('Q4 2025') // the closed round in view, not the open one
                    ->and($response->json('data.history'))->toBe([])
                    ->and($response->json('data.scores.overall.value'))->toBeNull();
                $body = str_replace('MARKER-OPENONLY Clinic', '', $body);
            }
            assertNothingLeaks("drawer {$facility}{$query}", $body, $response->json(), $this->adminEmail);
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(200);
});

it('does show the published figures, so the checks above are not vacuous', function (): void {
    $home = $this->get('/');
    expect($home->viewData('page')['props']['hero']['facilitiesVisited'])->toBe(22);

    $drawer = $this->getJson("/api/public/facilities/{$this->facilities[0]}")->assertOk();
    expect($drawer->json('data.round.label'))->toBe('Q4 2025')
        ->and($drawer->json('data.items'))->not->toBeEmpty();

    // And the same data does carry the markers for an administrator, on the admin side.
    $admin = User::query()->where('email', $this->adminEmail)->firstOrFail();
    $adminView = (string) $this->actingAs($admin)->get("/admin/facilities/{$this->facilities[0]}?round={$this->closed}")->getContent();
    expect($adminView)->toContain('MARKER-ASSESSOR')->toContain('MARKER-ATTACH');
});
