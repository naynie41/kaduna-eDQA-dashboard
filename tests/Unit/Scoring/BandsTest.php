<?php

declare(strict_types=1);

use App\Domain\Scoring\Bands;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Enums\Band;

// Bands classify the value as the table shows it (1 dp, configured mode), so a score shown as
// "90.0" is never labelled Acceptable (Q-26).

function bandRules(): RuleConfig
{
    return RuleConfig::fromArray([
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => [], 'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ]);
}

it('bands on the value rounded half up to 1 dp', function (float $value, Band $band): void {
    expect(Bands::classify($value, bandRules()))->toBe($band);
})->with([
    '89.94 → 89.9' => [89.94, Band::Acceptable],
    '89.95 → 90.0' => [89.95, Band::Strong],
    '90.0' => [90.0, Band::Strong],
    '79.94 → 79.9' => [79.94, Band::Review],
    '79.95 → 80.0' => [79.95, Band::Acceptable],
    '69.94 → 69.9' => [69.94, Band::NeedsAction],
    '69.95 → 70.0' => [69.95, Band::Review],
    '0' => [0.0, Band::NeedsAction],
    '100' => [100.0, Band::Strong],
]);

it('bands on the truncated value when display truncates', function (float $value, Band $band): void {
    config(['edqa.presentation.rounding' => 'truncate']);

    expect(Bands::classify($value, bandRules()))->toBe($band);
})->with([
    '89.99 → 89.9' => [89.99, Band::Acceptable],
    '89.95 → 89.9' => [89.95, Band::Acceptable],
    '90.0' => [90.0, Band::Strong],
    '69.99 → 69.9' => [69.99, Band::NeedsAction],
    '79.95 → 79.9' => [79.95, Band::Review],
]);

it("uses the rule version's own thresholds", function (): void {
    $strict = RuleConfig::fromArray([...bandRules()->toArray(), 'bands' => ['strong' => 95, 'acceptable' => 85, 'review' => 75]]);

    expect(Bands::classify(92.0, $strict))->toBe(Band::Acceptable)
        ->and(Bands::classify(92.0, bandRules()))->toBe(Band::Strong);
});
