<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAssessmentSeeder;
use Database\Seeders\LgaSeeder;
use Database\Seeders\Synthetic\SyntheticFacilityList;
use Illuminate\Support\Facades\DB;

const THE_23_LGAS = [
    'Birnin Gwari', 'Chikun', 'Giwa', 'Igabi', 'Ikara', 'Jaba', "Jema'a", 'Kachia',
    'Kaduna North', 'Kaduna South', 'Kagarko', 'Kajuru', 'Kaura', 'Kauru', 'Kubau', 'Kudan',
    'Lere', 'Makarfi', 'Sabon Gari', 'Sanga', 'Soba', 'Zangon Kataf', 'Zaria',
];

function runSeedersAs(string $environment, string ...$seeders): void
{
    app()->detectEnvironment(fn (): string => $environment);
    foreach ($seeders as $seeder) {
        // --force, as a real production seed needs: db:seed otherwise asks for confirmation.
        test()->artisan('db:seed', ['--class' => $seeder, '--force' => true])->run();
    }
}

// ---- LGAs (CLAUDE.md hard rule 9: exactly 23)

it('seeds exactly the 23 LGAs, even when run twice', function (): void {
    $this->seed(LgaSeeder::class);
    $this->seed(LgaSeeder::class);

    expect(DB::table('lgas')->count())->toBe(23)
        ->and(DB::table('lgas')->orderBy('name')->pluck('name')->all())->toBe(THE_23_LGAS)
        ->and(DB::table('lgas')->distinct()->count('code'))->toBe(23);
});

// ---- Production seeds only the reference data (no synthetic or demo data)

it('seeds only the LGAs and rule version 1 in production', function (): void {
    runSeedersAs('production', DatabaseSeeder::class);

    expect(DB::table('lgas')->count())->toBe(23)
        ->and(DB::table('scoring_rule_versions')->count())->toBe(1)
        ->and(DB::table('wards')->count())->toBe(0)
        ->and(DB::table('facilities')->count())->toBe(0)
        ->and(DB::table('rounds')->count())->toBe(0)
        ->and(DB::table('assessments')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(0);
});

it('refuses to run the demo seeder in production', function (): void {
    expect(fn () => runSeedersAs('production', DemoAssessmentSeeder::class))
        ->toThrow(RuntimeException::class, 'DemoAssessmentSeeder');

    expect(DB::table('assessments')->count())->toBe(0);
});

it('publishes rule version 1 with the default bands, unweighted, and the yes/no/na map', function (): void {
    runSeedersAs('production', DatabaseSeeder::class);

    $version = DB::table('scoring_rule_versions')->sole();
    expect($version->version)->toBe(1)
        ->and($version->published_at)->not->toBeNull()
        ->and($version->created_by)->toBeNull()
        // toEqual, not toBe: jsonb stores object keys in its own order.
        ->and(json_decode($version->config, true))->toEqual([
            'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
            'item_weights' => [],
            'na_policy' => 'exclude',
            'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
        ]);
});

// ---- The synthetic facility list (replaceable by the real master list)

it('builds about 2,000 public and exactly 219 private synthetic facilities', function (): void {
    $list = new SyntheticFacilityList;
    $facilities = collect($list->facilities());

    expect($facilities->where('ownership', 'public')->count())->toBe(1957)
        ->and($facilities->where('ownership', 'private')->count())->toBe(219)
        ->and($facilities->pluck('code')->unique()->count())->toBe($facilities->count())
        ->and($facilities->pluck('lga')->unique()->sort()->values()->all())->toBe(THE_23_LGAS)
        ->and($facilities->every(fn (array $f): bool => str_starts_with($f['code'], 'SYN-')))->toBeTrue();

    expect(collect($list->wards())->groupBy('lga')->map->count()->unique()->values()->all())->toBe([10]);
});

// ---- A full local seed

it('seeds a demo dataset whose item responses approximate the published means', function (): void {
    runSeedersAs('local', DatabaseSeeder::class);

    expect(DB::table('lgas')->count())->toBe(23)
        ->and(DB::table('facilities')->count())->toBe(2176)
        ->and(DB::table('rounds')->orderBy('year')->orderBy('quarter')->get(['year', 'quarter', 'status'])
            ->map(fn (object $r): string => "{$r->year}-Q{$r->quarter} {$r->status}")->all())
        ->toBe(['2025-Q3 closed', '2025-Q4 closed', '2026-Q1 closed', '2026-Q2 open'])
        ->and(DB::table('assessments')->count())->toBeGreaterThan(1000)
        // Scoring arrives in Phase 3: item responses only, no scores.
        ->and(DB::table('assessment_scores')->count())->toBe(0);

    // Every facility sits in a ward of its own LGA.
    expect(DB::table('facilities')->join('wards', 'wards.id', '=', 'facilities.ward_id')
        ->whereColumn('wards.lga_id', '<>', 'facilities.lga_id')->count())->toBe(0);

    // Means computed the way the calculator will: pass rate per dimension-month (N/A excluded),
    // mean of the slots per facility, then unweighted mean over facilities.
    $means = collect(DB::select(<<<'SQL'
        with cell as (
            select assessment_id, dimension, month_slot,
                   avg(case when value = 'yes' then 100.0 else 0 end) filter (where is_applicable) as score
            from item_responses group by 1, 2, 3
        ), per_assessment as (
            select a.id, f.ownership, c.dimension, avg(c.score) as score
            from cell c join assessments a on a.id = c.assessment_id join facilities f on f.id = a.facility_id
            group by 1, 2, 3
        )
        select ownership, dimension, avg(score) as mean from per_assessment group by 1, 2
        SQL))->mapWithKeys(fn (object $r): array => ["{$r->ownership}.{$r->dimension}" => (float) $r->mean]);

    $targets = [
        'public.availability' => 92, 'public.consistency' => 79, 'public.validity' => 92,
        'private.availability' => 75, 'private.consistency' => 62, 'private.validity' => 84,
    ];
    foreach ($targets as $key => $target) {
        expect($means[$key])->toBeGreaterThan($target - 2.5, $key)->toBeLessThan($target + 2.5, $key);
    }
});
