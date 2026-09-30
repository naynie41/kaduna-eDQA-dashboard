<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

/**
 * Nine assessment_scores rows (3 dimensions × 3 month slots) for one assessment and version.
 *
 * @param  array<string, array{0: float|null, 1: float|null, 2: float|null}>  $scores
 */
function scoreAssessment(int $assessmentId, int $ruleVersionId, array $scores): void
{
    foreach ($scores as $dimension => $slots) {
        foreach ($slots as $i => $score) {
            Rows::score([
                'assessment_id' => $assessmentId,
                'rule_version_id' => $ruleVersionId,
                'dimension' => $dimension,
                'month_slot' => $i + 1,
                'score' => $score,
            ]);
        }
    }
}

/**
 * @return array<string, float|int|null>
 */
function aggregateRow(int $roundId, string $scope, int $scopeId, string $owner = 'all', string $level = 'all'): array
{
    $row = DB::table('round_aggregates')
        ->where(['round_id' => $roundId, 'scope_type' => $scope, 'scope_id' => $scopeId, 'owner_type' => $owner, 'level' => $level])
        ->first();

    expect($row)->not->toBeNull("no {$scope}/{$scopeId}/{$owner}/{$level} row");

    return collect((array) $row)
        ->only(['availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall', 'facility_count'])
        ->map(fn (mixed $v): float|int|null => $v === null ? null : (is_int($v) ? $v : round((float) $v, 4)))
        ->all();
}

it('is created without data and refreshes concurrently once populated', function (): void {
    $populated = fn (): bool => (bool) DB::table('pg_matviews')->where('matviewname', 'round_aggregates')->value('ispopulated');

    expect($populated())->toBeFalse();

    DB::select('select refresh_round_aggregates()');   // first call populates (plain refresh)
    expect($populated())->toBeTrue();

    DB::select('select refresh_round_aggregates()');   // then CONCURRENTLY
    DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY round_aggregates');

    expect($populated())->toBeTrue();
});

it('aggregates facility scores upward, unweighted, for the current published rule version', function (): void {
    $admin = Rows::user();
    $oldVersion = Rows::ruleVersion(['version' => 1, 'published_at' => now()->subYear(), 'created_by' => $admin]);
    $current = Rows::ruleVersion(['version' => 2, 'published_at' => now()->subMonth(), 'created_by' => $admin]);
    $draft = Rows::ruleVersion(['version' => 3, 'published_at' => null, 'created_by' => $admin]);

    $lgaA = Rows::lga();
    $lgaB = Rows::lga();
    $wardA1 = Rows::ward(['lga_id' => $lgaA]);
    $wardB1 = Rows::ward(['lga_id' => $lgaB]);
    $f1 = Rows::facility(['lga_id' => $lgaA, 'ward_id' => $wardA1, 'ownership' => 'public', 'level' => 'primary']);
    $f2 = Rows::facility(['lga_id' => $lgaA, 'ward_id' => $wardA1, 'ownership' => 'private', 'level' => 'primary']);
    $f3 = Rows::facility(['lga_id' => $lgaB, 'ward_id' => $wardB1, 'ownership' => 'public', 'level' => 'secondary']);
    Rows::facility(['lga_id' => $lgaB, 'ward_id' => $wardB1]);   // never assessed: not counted
    $round = Rows::round();

    $a1 = Rows::assessment(['round_id' => $round, 'facility_id' => $f1]);
    $a2 = Rows::assessment(['round_id' => $round, 'facility_id' => $f2]);
    $a3 = Rows::assessment(['round_id' => $round, 'facility_id' => $f3]);

    // F1: 90 / 80 / 100 → overall 90
    scoreAssessment($a1, $current, ['availability' => [90, 90, 90], 'consistency' => [80, 80, 80], 'validity' => [100, 100, 100]]);
    // F2: consistency month 3 all-N/A (NULL) → 70 from the two remaining slots; 60 / 70 / 80 → 70
    scoreAssessment($a2, $current, ['availability' => [60, 60, 60], 'consistency' => [70, 70, null], 'validity' => [80, 80, 80]]);
    // F3: 100 / 90 / 80 → overall 90
    scoreAssessment($a3, $current, ['availability' => [100, 100, 100], 'consistency' => [90, 90, 90], 'validity' => [80, 80, 80]]);
    // Other versions must be ignored: an older published one and a newer unpublished draft.
    scoreAssessment($a1, $oldVersion, ['availability' => [10, 10, 10], 'consistency' => [10, 10, 10], 'validity' => [10, 10, 10]]);
    scoreAssessment($a1, $draft, ['availability' => [20, 20, 20], 'consistency' => [20, 20, 20], 'validity' => [20, 20, 20]]);

    DB::select('select refresh_round_aggregates()');

    // State: unweighted mean over the three assessed facilities.
    expect(aggregateRow($round, 'state', 0))->toBe([
        'availability' => 83.3333, 'consistency' => 80.0, 'validity' => 86.6667,
        'm1' => 83.3333, 'm2' => 83.3333, 'm3' => 83.3333,
        'overall' => 83.3333, 'facility_count' => 3,
    ]);

    // Owner split within the state.
    expect(aggregateRow($round, 'state', 0, 'public'))
        ->toMatchArray(['availability' => 95.0, 'consistency' => 85.0, 'validity' => 90.0, 'overall' => 90.0, 'facility_count' => 2])
        ->and(aggregateRow($round, 'state', 0, 'private'))
        ->toMatchArray(['overall' => 70.0, 'facility_count' => 1]);

    // Level split, and owner × level together.
    expect(aggregateRow($round, 'state', 0, 'all', 'primary'))->toMatchArray(['overall' => 80.0, 'facility_count' => 2])
        ->and(aggregateRow($round, 'state', 0, 'public', 'primary'))->toMatchArray(['overall' => 90.0, 'facility_count' => 1]);

    // LGA and ward scopes.
    expect(aggregateRow($round, 'lga', $lgaA))->toMatchArray(['overall' => 80.0, 'facility_count' => 2])
        ->and(aggregateRow($round, 'lga', $lgaB))->toMatchArray(['overall' => 90.0, 'facility_count' => 1])
        ->and(aggregateRow($round, 'ward', $wardA1))->toMatchArray(['overall' => 80.0, 'facility_count' => 2]);

    // Facility scope: an all-N/A slot is excluded, not counted as zero.
    expect(aggregateRow($round, 'facility', $f2))->toBe([
        'availability' => 60.0, 'consistency' => 70.0, 'validity' => 80.0,
        'm1' => 70.0, 'm2' => 70.0, 'm3' => 70.0,
        'overall' => 70.0, 'facility_count' => 1,
    ]);
});

it('picks up new scores on the next refresh', function (): void {
    $version = Rows::ruleVersion(['published_at' => now()]);
    $round = Rows::round();
    DB::select('select refresh_round_aggregates()');
    expect(DB::table('round_aggregates')->where('round_id', $round)->count())->toBe(0);

    scoreAssessment(Rows::assessment(['round_id' => $round]), $version, [
        'availability' => [50, 50, 50], 'consistency' => [50, 50, 50], 'validity' => [50, 50, 50],
    ]);
    DB::select('select refresh_round_aggregates()');

    expect(aggregateRow($round, 'state', 0))->toMatchArray(['overall' => 50.0, 'facility_count' => 1]);
});

it('does not let every role execute the SECURITY DEFINER function', function (): void {
    $function = DB::table('pg_proc')->where('proname', 'refresh_round_aggregates')
        ->first([DB::raw('prosecdef as security_definer'), DB::raw('proacl::text as acl')]);

    expect($function)->not->toBeNull()
        ->and($function->security_definer)->toBeTrue()
        // A NULL ACL means the defaults, which let PUBLIC execute: the migration must set one.
        ->and($function->acl)->not->toBeNull()
        // An entry for PUBLIC starts with "=" (e.g. "=X/owner").
        ->and($function->acl)->not->toContain('{=X')->not->toContain(',=X');
});
