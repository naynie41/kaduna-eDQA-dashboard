<?php

declare(strict_types=1);

use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// Phase 1 acceptance (CLAUDE.md §6, step 2): the live report's 347.66 can never be stored.
it('rejects a 347.66 score at the database level', function (): void {
    expectCheckViolation(fn () => Rows::score(['score' => 347.66]), 'score_in_range');
});

it('accepts the boundaries 0 and 100', function (float $score): void {
    expect(Rows::score(['score' => $score]))->toBeInt();
})->with([0.0, 100.0]);

it('rejects scores just outside 0 to 100', function (float $score): void {
    expectCheckViolation(fn () => Rows::score(['score' => $score]), 'score_in_range');
})->with([-0.01, 100.01]);

it('stores a null score for an all-N/A month slot', function (): void {
    $id = Rows::score(['score' => null]);

    expect(DB::table('assessment_scores')->where('id', $id)->value('score'))->toBeNull();
});

it('keeps full precision to two decimals', function (): void {
    $id = Rows::score(['score' => 73.67]);

    expect(DB::table('assessment_scores')->where('id', $id)->value('score'))->toBe('73.67');
});

it('rejects a month slot outside 1 to 3', function (int $slot): void {
    expectCheckViolation(fn () => Rows::score(['month_slot' => $slot]), 'score_slot_valid');
})->with([0, 4]);

it('accepts every Dimension the app defines', function (Dimension $dimension): void {
    expect(Rows::score(['dimension' => $dimension->value]))->toBeInt();
})->with(fn (): array => Dimension::cases());

it('rejects an unknown dimension', function (): void {
    expectCheckViolation(fn () => Rows::score(['dimension' => 'timeliness']), 'assessment_scores_dimension_valid');
});

it('rejects a second score for the same assessment, dimension, slot and rule version', function (): void {
    $assessmentId = Rows::assessment();
    $versionId = Rows::ruleVersion();
    Rows::score(['assessment_id' => $assessmentId, 'rule_version_id' => $versionId]);

    expectUniqueViolation(
        fn () => Rows::score(['assessment_id' => $assessmentId, 'rule_version_id' => $versionId]),
        'assessment_scores_slot_version_unique',
    );
});

// D-05: a rescore under a new rule version keeps the old version's score readable.
it('keeps scores from an older rule version alongside a rescore', function (): void {
    $assessmentId = Rows::assessment();
    Rows::score(['assessment_id' => $assessmentId, 'rule_version_id' => Rows::ruleVersion(), 'score' => 80]);
    Rows::score(['assessment_id' => $assessmentId, 'rule_version_id' => Rows::ruleVersion(), 'score' => 85]);

    expect(DB::table('assessment_scores')->where('assessment_id', $assessmentId)->count())->toBe(2);
});

it('deletes an assessment\'s scores and item responses with it', function (): void {
    $assessmentId = Rows::assessment();
    Rows::score(['assessment_id' => $assessmentId]);
    Rows::itemResponse(['assessment_id' => $assessmentId]);

    DB::table('assessments')->where('id', $assessmentId)->delete();

    expect(DB::table('assessment_scores')->where('assessment_id', $assessmentId)->count())->toBe(0)
        ->and(DB::table('item_responses')->where('assessment_id', $assessmentId)->count())->toBe(0);
});
