<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\IncompleteAssessmentException;
use App\Domain\Scoring\ScoreCalculator;

// ARCHITECTURE.md §7. Pure: no database, no config(), so plain unit tests.

function defaultRules(array $weights = []): RuleConfig
{
    return RuleConfig::fromArray([
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => $weights,
        'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ]);
}

/**
 * Items for one slot from a string like "PPPF-" (P pass, F fail, - N/A), codes item_1, item_2...
 *
 * @return list<ItemResponseData>
 */
function slot(Dimension $dimension, int $month, string $answers): array
{
    $values = ['P' => 'pass', 'F' => 'fail', '-' => 'na'];
    $items = [];
    foreach (str_split($answers) as $i => $answer) {
        $value = $values[$answer];
        $items[] = new ItemResponseData($dimension, $month, 'item_'.($i + 1), $value, $value !== 'na');
    }

    return $items;
}

/**
 * The same answers in all nine slots, unless a slot is given in $overrides as "dimension:month".
 *
 * @param  array<string, string>  $overrides
 * @return list<ItemResponseData>
 */
function assessment(string $answers, array $overrides = []): array
{
    $items = [];
    foreach (Dimension::cases() as $dimension) {
        foreach ([1, 2, 3] as $month) {
            $items = [...$items, ...slot($dimension, $month, $overrides["{$dimension->value}:{$month}"] ?? $answers)];
        }
    }

    return $items;
}

// ---- Hand-worked examples

it('scores 7 of 8 passed items as 87.5', function (): void {
    $card = (new ScoreCalculator)->calculate(assessment('PPPPPPPF'), defaultRules());

    expect($card->slots['availability'][1])->toBe(87.5)
        ->and($card->dimensions['consistency'])->toBe(87.5)
        ->and($card->overall)->toBe(87.5)
        ->and($card->exclusions)->toBe([]);
});

it('leaves N/A items out of both the numerator and the denominator', function (): void {
    // 3 passed of 4 applicable; the two N/A items count for nothing.
    $card = (new ScoreCalculator)->calculate(assessment('PP-PF-'), defaultRules());

    expect($card->slots['validity'][2])->toBe(75.0);
});

it('makes an all-N/A slot null, averages the other slots and records the exclusion', function (): void {
    $card = (new ScoreCalculator)->calculate(assessment('PPPF', [
        'availability:2' => '---',
        'availability:3' => 'PPPP',
    ]), defaultRules());

    expect($card->slots['availability'])->toBe([1 => 75.0, 2 => null, 3 => 100.0])
        ->and($card->dimensions['availability'])->toBe(87.5)
        ->and($card->exclusions)->toBe(['SLOT_ALL_NA:availability:2']);
});

it('averages the three dimensions, not the nine slots, for the overall score', function (): void {
    // Availability 100 over two slots (slot 1 all N/A), consistency 50, validity 0:
    // dimensions mean 50; a mean over the eight scored slots would give 43.75.
    $card = (new ScoreCalculator)->calculate(assessment('FF', [
        'availability:1' => '--', 'availability:2' => 'PP', 'availability:3' => 'PP',
        'consistency:1' => 'PF', 'consistency:2' => 'PF', 'consistency:3' => 'PF',
    ]), defaultRules());

    expect($card->dimensions)->toBe(['availability' => 100.0, 'consistency' => 50.0, 'validity' => 0.0])
        ->and($card->overall)->toBe(50.0);
});

it('keeps full precision: no rounding anywhere', function (): void {
    // 2 of 3 = 66.666...%; availability = (66.666... + 100 + 100) / 3 = 88.888...
    $card = (new ScoreCalculator)->calculate(assessment('PPPP', ['availability:1' => 'PPF']), defaultRules());
    $availability = (2 / 3 * 100 + 100 + 100) / 3;

    expect($card->slots['availability'][1])->toBe(2 / 3 * 100)
        ->and($card->dimensions['availability'])->toBe($availability)
        ->and($card->overall)->toBe(($availability + 100 + 100) / 3);
});

it('ignores the order the items arrive in', function (): void {
    $items = assessment('PPF-PFPP');
    $calculator = new ScoreCalculator;

    expect($calculator->calculate(array_reverse($items), defaultRules()))
        ->toEqual($calculator->calculate($items, defaultRules()));
});

// ---- Weights

it('weights items: Σ(weight × pass) / Σ(weight × applicable) × 100', function (): void {
    // item_1 (weight 3) passes, item_2 (weight 1) fails: 3 / 4 = 75, where unweighted gives 50.
    $rules = defaultRules(['availability.item_1' => 3, 'availability.item_2' => 1]);
    $card = (new ScoreCalculator)->calculate(assessment('PF'), $rules);

    expect($card->dimensions['availability'])->toBe(75.0)
        ->and($card->dimensions['consistency'])->toBe(50.0);
});

it('gives unlisted items a weight of 1, and a weighted N/A item still counts for nothing', function (): void {
    $rules = defaultRules(['validity.item_3' => 5]);
    $card = (new ScoreCalculator)->calculate(assessment('PF-'), $rules);

    expect($card->dimensions['validity'])->toBe(50.0);
});

it('refuses a weight that is not a positive number', function (mixed $weight): void {
    expect(fn () => defaultRules(['availability.item_1' => $weight]))->toThrow(InvalidArgumentException::class);
})->with([0, -1, 'heavy']);

// ---- Determinism (goal G3)

it('produces an identical score card from identical input', function (): void {
    $items = assessment('PPF-PFPPF', ['validity:3' => '----']);
    $rules = defaultRules(['consistency.item_2' => 2.5]);

    $first = (new ScoreCalculator)->calculate($items, $rules);
    $second = (new ScoreCalculator)->calculate($items, $rules);

    expect($second->toArray())->toBe($first->toArray())
        ->and(serialize($second))->toBe(serialize($first));
});

// ---- Property: any valid item set scores within 0–100

it('keeps every score within 0 to 100 for 1,000 random valid item sets', function (): void {
    mt_srand(20261008); // fixed seed: a failure reproduces
    $calculator = new ScoreCalculator;
    $values = ['pass', 'fail', 'na'];

    for ($run = 0; $run < 1000; $run++) {
        $items = [];
        $weights = [];
        foreach (Dimension::cases() as $dimension) {
            // At least one applicable item somewhere in each dimension, so it is never all null.
            $items[] = new ItemResponseData($dimension, mt_rand(1, 3), 'anchor', mt_rand(0, 1) === 1 ? 'pass' : 'fail', true);
            foreach ([1, 2, 3] as $month) {
                for ($i = 0, $n = mt_rand(0, 12); $i < $n; $i++) {
                    $value = $values[mt_rand(0, 2)];
                    $items[] = new ItemResponseData($dimension, $month, "item_{$i}", $value, $value !== 'na');
                }
            }
            if (mt_rand(0, 1) === 1) {
                $weights["{$dimension->value}.item_".mt_rand(0, 11)] = mt_rand(1, 1000) / 100;
            }
        }

        $card = $calculator->calculate($items, defaultRules($weights));
        $scores = [$card->overall, ...array_values($card->dimensions)];
        foreach ($card->slots as $months) {
            $scores = [...$scores, ...array_filter($months, fn (?float $s): bool => $s !== null)];
        }

        foreach ($scores as $score) {
            expect($score)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
        }
    }
});

// ---- Safeguard

it('throws when a whole dimension has no applicable item', function (string $answers): void {
    $items = assessment('PPF', [
        'consistency:1' => $answers, 'consistency:2' => $answers, 'consistency:3' => $answers,
    ]);

    expect(fn () => (new ScoreCalculator)->calculate($items, defaultRules()))
        ->toThrow(IncompleteAssessmentException::class, 'consistency');
})->with([
    'all N/A' => ['---'],
    'no items' => [''],
]);

it('throws for an empty item list', function (): void {
    expect(fn () => (new ScoreCalculator)->calculate([], defaultRules()))
        ->toThrow(IncompleteAssessmentException::class);
});
