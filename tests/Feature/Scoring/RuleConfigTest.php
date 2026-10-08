<?php

declare(strict_types=1);

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use Database\Seeders\ScoringRuleVersionSeeder;

it('reads the seeded rule version 1', function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);

    $rules = RuleConfig::fromVersion(ScoringRuleVersion::query()->where('version', 1)->sole());

    expect($rules->bands)->toBe(['strong' => 90.0, 'acceptable' => 80.0, 'review' => 70.0])
        ->and($rules->itemWeights)->toBe([])
        ->and($rules->naPolicy)->toBe('exclude')
        // toEqual: jsonb stores object keys in its own order.
        ->and($rules->choiceMap)->toEqual(['yes' => 'pass', 'no' => 'fail', 'na' => 'na'])
        ->and($rules->weightFor('availability', 'summary_form'))->toBe(1.0);
});

it('round-trips the stored config', function (): void {
    $config = [
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => ['availability.summary_form' => 2.5],
        'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ];
    $version = ScoringRuleVersion::factory()->create(['config' => $config]);

    $rules = RuleConfig::fromVersion($version);

    expect($rules->toArray())->toEqual($config)
        ->and($rules->weightFor('availability', 'summary_form'))->toBe(2.5);
});

it('refuses a config it cannot score with, rather than guessing', function (array $change): void {
    $config = array_replace([
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => [],
        'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ], $change);

    expect(fn () => RuleConfig::fromArray($config))->toThrow(InvalidArgumentException::class);
})->with([
    'bands out of order' => [['bands' => ['strong' => 80, 'acceptable' => 90, 'review' => 70]]],
    'band above 100' => [['bands' => ['strong' => 101, 'acceptable' => 80, 'review' => 70]]],
    'band missing' => [['bands' => ['strong' => 90, 'acceptable' => 80]]],
    'unknown N/A policy' => [['na_policy' => 'count_as_fail']],
    'choice mapped to a non-outcome' => [['choice_map' => ['yes' => 'pass', 'no' => 'maybe']]],
    'weight key without a dimension' => [['item_weights' => ['summary_form' => 2]]],
    'weight for an unknown dimension' => [['item_weights' => ['quality.summary_form' => 2]]],
]);
