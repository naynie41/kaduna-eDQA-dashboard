<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Reporting\Aggregators\RuleVersionPreviewAggregator;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\CreateDraftRuleVersion;
use App\Domain\Scoring\Enums\Band;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;

// Preview (ARCHITECTURE.md §7 Rule versions): the band changes a draft would cause, per open
// round, recomputed in memory from stored item responses. It writes nothing.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->v1 = ScoringRuleVersion::query()->where('version', 1)->sole();
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->lga = Lga::factory()->create(['code' => 'CHK', 'name' => 'Chikun']);
    $this->ward = Ward::factory()->create(['lga_id' => $this->lga->id]);
    $this->open = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
});

function assessedFacility(Round $round, Ward $ward, float $score, string $code): Facility
{
    $facility = Facility::factory()->inWard($ward)->create(['code' => $code]);
    Assessment::factory()->forRound($round)
        ->withScores(['availability' => $score, 'consistency' => $score, 'validity' => $score])
        ->create(['facility_id' => $facility->id]);

    return $facility;
}

function draft(array $changes): ScoringRuleVersion
{
    return app(CreateDraftRuleVersion::class)->execute(test()->v1, $changes, test()->admin);
}

it('lists the facilities and LGAs whose band would change, with before and after', function (): void {
    assessedFacility($this->open, $this->ward, 92, 'KD-0001');   // Strong now; Acceptable once Strong needs 95
    assessedFacility($this->open, $this->ward, 97, 'KD-0002');   // Strong either way
    assessedFacility($this->open, $this->ward, 75, 'KD-0003');   // Review either way
    // LGA mean: (92 + 97 + 75) / 3 = 88: Acceptable either way.

    $preview = app(RuleVersionPreviewAggregator::class)->preview(draft(['bands' => ['strong' => 95, 'acceptable' => 80, 'review' => 70]]));

    expect($preview->rounds)->toHaveCount(1);
    $round = $preview->rounds[0];
    expect($round->roundId)->toBe($this->open->id)
        ->and($round->assessments)->toBe(3)
        ->and($round->facilitiesChangingBand)->toHaveCount(1)
        ->and($round->facilitiesChangingBand[0]['code'])->toBe('KD-0001')
        ->and($round->facilitiesChangingBand[0]['before']->band)->toBe(Band::Strong)
        ->and($round->facilitiesChangingBand[0]['after']->band)->toBe(Band::Acceptable)
        ->and($round->facilitiesChangingBand[0]['before']->value)->toEqualWithDelta(92.0, 0.0001)
        ->and($round->facilitiesChangingBand[0]['after']->display)->toBe('92.0')
        ->and($round->lgasChangingBand)->toBe([])
        ->and($round->stateOverallBefore->value)->toEqualWithDelta(88.0, 0.0001)
        ->and($round->stateOverallAfter->value)->toEqualWithDelta(88.0, 0.0001);
});

it('reports an LGA crossing a band and the state overall moving when the scoring changes', function (): void {
    assessedFacility($this->open, $this->ward, 85, 'KD-0001');
    $other = Ward::factory()->create(['lga_id' => Lga::factory()->create(['code' => 'GWA', 'name' => 'Giwa'])->id]);
    assessedFacility($this->open, $other, 50, 'KD-0002');

    // Raising Acceptable to 86 moves Chikun (85) down to Review; Giwa (50) stays Needs action.
    $preview = app(RuleVersionPreviewAggregator::class)->preview(draft(['bands' => ['strong' => 90, 'acceptable' => 86, 'review' => 70]]));

    $round = $preview->rounds[0];
    expect($round->lgasChangingBand)->toHaveCount(1)
        ->and($round->lgasChangingBand[0]['code'])->toBe('CHK')
        ->and($round->lgasChangingBand[0]['before']->band)->toBe(Band::Acceptable)
        ->and($round->lgasChangingBand[0]['after']->band)->toBe(Band::Review)
        ->and($round->stateOverallBefore->value)->toEqualWithDelta(67.5, 0.0001);
});

it('applies a changed choice map to answers stored as raw choices', function (): void {
    // The factory stores raw "yes"/"no"/"na". Mapping "na" to fail stops excluding the N/A item.
    assessedFacility($this->open, $this->ward, 100, 'KD-0001');

    $preview = app(RuleVersionPreviewAggregator::class)->preview(draft(['choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'fail', 'skip' => 'na']]));

    $round = $preview->rounds[0];
    expect($round->stateOverallBefore->value)->toEqualWithDelta(100.0, 0.0001)
        ->and($round->stateOverallAfter->value)->toBeLessThan(100.0)
        ->and($round->facilitiesChangingBand[0]['before']->band ?? null)->toBe(Band::Strong);
});

it('covers open rounds only: a closed round keeps its pinned version', function (): void {
    $closed = Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]);
    assessedFacility($closed, $this->ward, 92, 'KD-0001');
    assessedFacility($this->open, $this->ward, 92, 'KD-0002');

    $preview = app(RuleVersionPreviewAggregator::class)->preview(draft(['bands' => ['strong' => 95, 'acceptable' => 80, 'review' => 70]]));

    expect(array_map(fn ($r) => $r->roundId, $preview->rounds))->toBe([$this->open->id]);
});

it('writes nothing', function (): void {
    foreach (range(1, 3) as $i) {
        assessedFacility($this->open, $this->ward, 70 + $i * 7, "KD-000{$i}");
    }
    $draft = draft(['bands' => ['strong' => 95, 'acceptable' => 80, 'review' => 70]]);
    $tables = ['assessments', 'assessment_scores', 'item_responses', 'rounds', 'scoring_rule_versions', 'activity_log', 'facilities'];
    $snapshot = fn (): array => array_map(fn (string $t): array => [
        DB::table($t)->count(),
        DB::table($t)->max(in_array($t, ['item_responses', 'assessment_scores'], true) ? 'id' : 'updated_at'),
    ], array_combine($tables, $tables));
    $before = $snapshot();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(RuleVersionPreviewAggregator::class)->preview($draft);
    $statements = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect($snapshot())->toBe($before)
        ->and(array_filter($statements, fn (string $q): bool => preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $q) === 1))->toBe([]);
});

it('reads items in chunks, so its query count does not grow with every assessment', function (): void {
    foreach (range(1, 12) as $i) {
        assessedFacility($this->open, $this->ward, 80, sprintf('KD-%04d', $i));
    }
    $draft = draft(['bands' => ['strong' => 95, 'acceptable' => 80, 'review' => 70]]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(RuleVersionPreviewAggregator::class)->preview($draft, chunkSize: 5);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Rounds + versions + 3 chunks of (assessments + items): far fewer than one per assessment.
    expect($queries)->toBeLessThan(12);
});

it('sends every score in the ScoreValue shape, the only one the API returns', function (): void {
    assessedFacility($this->open, $this->ward, 92, 'KD-0001');

    $array = app(RuleVersionPreviewAggregator::class)->preview(draft(['bands' => ['strong' => 95, 'acceptable' => 80, 'review' => 70]]))->toArray();
    $round = json_decode(json_encode($array, JSON_THROW_ON_ERROR), true)['rounds'][0];

    expect(array_keys($round['state_overall_before']))->toBe(['value', 'display', 'headline', 'band', 'bandLabel'])
        ->and($round['facilities_changing_band'][0]['before'])->toMatchArray(['display' => '92.0', 'band' => 'strong', 'bandLabel' => 'Strong'])
        ->and($round['facilities_changing_band'][0]['after'])->toMatchArray(['display' => '92.0', 'band' => 'acceptable']);
});
