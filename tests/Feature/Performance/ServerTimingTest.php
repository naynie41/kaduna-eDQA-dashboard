<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\FacilitySeeder;
use Database\Seeders\LgaSeeder;
use Database\Seeders\PerformanceSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Database\Seeders\WardSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

// ARCHITECTURE.md §10: p95 < 1.5 s server time for every dashboard page and API endpoint, any
// filter. On the performance dataset (~44,000 scored assessments over 20 rounds), each page's
// first response and its deferred-props reload, and each GET API endpoint, are timed over ten
// filter combinations; every combination is a first request, so nothing is served from the
// round cache. One warm-up request per endpoint (another filter) loads the classes first, so
// the dev stack's slow bind mount is not timed. Run with `make test-slow`; the table is written
// to storage/app/perf/server-timing.md.

const P95_BUDGET_SECONDS = 1.5;

it('answers every dashboard page and API endpoint within the p95 budget on five years of data', function (): void {
    $this->seed([LgaSeeder::class, ScoringRuleVersionSeeder::class, WardSeeder::class, FacilitySeeder::class]);
    $seeder = app(PerformanceSeeder::class);
    $seeded = hrtime(true);
    $seeder->run();
    $seedSeconds = (hrtime(true) - $seeded) / 1e9;
    expect($seeder->created)->toBeGreaterThan(40_000);

    $admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($admin);

    $lgas = DB::table('lgas')->orderBy('name')->pluck('code')->all();
    $years = DB::table('rounds')->distinct()->orderBy('year')->pluck('year')->all();
    [$first, $last] = [(int) $years[0], (int) end($years)];
    $filters = [
        '',
        "?year={$last}",
        "?year={$last}&quarter=2",
        '?year='.($last - 1),
        '?year='.($last - 2).'&quarter=3',
        "?lga={$lgas[3]}",
        '?owner=private',
        '?level=secondary',
        "?year={$last}&quarter=2&lga={$lgas[7]}&owner=public",
        "?year={$first}&quarter=4&lga={$lgas[12]}&level=primary&owner=public",
    ];
    $facilityExtras = ['', '&metric=availability', '&sort=lga&dir=desc', '&page=3', '&metric=validity&sort=m2', '&sort=facility', '&metric=consistency&page=2', '&sort=assessed&dir=desc', '&sort=owner', '&metric=overall&sort=m3&dir=desc'];

    $timings = [];
    $time = function (string $name, string $url, array $headers = []) use (&$timings): TestResponse {
        $started = hrtime(true);
        $response = $this->get($url, $headers);
        $timings[$name][] = (hrtime(true) - $started) / 1e9;

        return $response;
    };

    foreach (['home', 'facility', 'lga', 'monitor', 'visuals', 'plan'] as $page) {
        // Warm-up: another filter, untimed.
        $warm = $this->get("/dashboard/{$page}?year=".($last - 3))->assertOk();
        $deferred = $warm->viewData('page')['deferredProps'] ?? [];
        foreach ($deferred as $props) {
            $this->get("/dashboard/{$page}?year=".($last - 3), inertiaReload($warm, $props))->assertOk();
        }

        foreach ($filters as $i => $query) {
            $url = "/dashboard/{$page}".($query === '' ? '?' : $query).($page === 'facility' ? $facilityExtras[$i] : '');
            $url = rtrim($url, '?');
            $response = $time("{$page} (page)", $url)->assertOk();
            foreach ($response->viewData('page')['deferredProps'] ?? [] as $group => $props) {
                $time("{$page} (deferred: ".implode(', ', $props).')', $url, inertiaReload($response, $props))->assertOk();
            }
        }
    }

    // The drawer: ten facilities, each on the round the filters put in view.
    $facilityIds = DB::table('facilities')->where('is_active', true)->orderBy('id')->pluck('id')->all();
    $this->getJson("/api/facilities/{$facilityIds[0]}")->assertOk();
    foreach ($filters as $i => $query) {
        $facility = $facilityIds[intdiv(count($facilityIds) * ($i + 1), 11)];
        $time('api facilities/{id}', "/api/facilities/{$facility}{$query}", ['Accept' => 'application/json'])->assertOk();
    }

    $roundIds = DB::table('rounds')->orderBy('id')->pluck('id')->all();
    $api = [
        'api rounds' => array_fill(0, 10, '/api/rounds'),
        'api scoring-rules' => array_fill(0, 10, '/api/scoring-rules'),
        'api quarantine' => ['', '?group=rule', '?status=open', '?status=resolved', '?rule=UNKNOWN_FORM_VERSION', "?round={$roundIds[3]}",
            "?lga={$lgas[2]}", '?group=flat&page=2', "?group=rule&round={$roundIds[10]}", "?status=open&lga={$lgas[5]}"],
    ];
    foreach ($api as $name => $urls) {
        $this->getJson(str_starts_with($urls[0], '/') ? $urls[0] : "/api/quarantine{$urls[0]}")->assertOk();
        foreach ($urls as $url) {
            $time($name, str_starts_with($url, '/') ? $url : "/api/quarantine{$url}", ['Accept' => 'application/json'])->assertOk();
        }
    }

    $report = serverTimingReport($timings, $seeder->created, count($seeder->rounds), $seedSeconds);
    @mkdir(storage_path('app/perf'), 0775, true);
    file_put_contents(storage_path('app/perf/server-timing.md'), $report);
    fwrite(STDERR, "\n".$report);

    $over = array_filter(array_map(fn (array $samples): float => percentile($samples, 0.95), $timings), fn (float $p95): bool => $p95 >= P95_BUDGET_SECONDS);
    expect($over)->toBe([]);
})->group('slow');

/** The headers of an Inertia partial reload for these props, as the browser sends it. */
function inertiaReload(TestResponse $response, array $props): array
{
    $page = $response->viewData('page');

    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) ($page['version'] ?? ''),
        'X-Inertia-Partial-Component' => (string) $page['component'],
        'X-Inertia-Partial-Data' => implode(',', $props),
    ];
}

/** Nearest-rank percentile. */
function percentile(array $samples, float $p): float
{
    sort($samples);

    return $samples[max(0, (int) ceil($p * count($samples)) - 1)];
}

function serverTimingReport(array $timings, int $assessments, int $rounds, float $seedSeconds): string
{
    $lines = [
        '# Server timing',
        '',
        sprintf('%s assessments over %d rounds (seeded in %.1f s). In-process request time, warm classes, cold round cache; 10 filter combinations per row. Budget: p95 < %.1f s.', number_format($assessments), $rounds, $seedSeconds, P95_BUDGET_SECONDS),
        '',
        '| Endpoint | p50 (ms) | p95 (ms) | max (ms) | |',
        '|---|---:|---:|---:|---|',
    ];
    foreach ($timings as $name => $samples) {
        $p95 = percentile($samples, 0.95);
        $lines[] = sprintf('| %s | %d | %d | %d | %s |', $name, percentile($samples, 0.5) * 1000, $p95 * 1000, max($samples) * 1000, $p95 < P95_BUDGET_SECONDS ? 'ok' : 'OVER');
    }

    return implode("\n", $lines)."\n";
}
