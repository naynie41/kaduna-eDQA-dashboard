<?php

declare(strict_types=1);

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Scoring\Enums\Band;

function scoreRules(): RuleConfig
{
    return RuleConfig::fromArray([
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => [], 'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ]);
}

it('keeps full precision and presents one decimal, a headline and a labelled band', function (): void {
    $score = ScoreValue::from(87.666666666, scoreRules());

    expect($score->value)->toBe(87.666666666)
        ->and($score->display)->toBe('87.7')
        ->and($score->headline)->toBe('88')
        ->and($score->band)->toBe(Band::Acceptable)
        ->and($score->bandLabel)->toBe('Acceptable');
});

it('never shows "90.0" labelled Acceptable', function (): void {
    $score = ScoreValue::from(89.96, scoreRules());

    expect($score->display)->toBe('90.0')
        ->and($score->band)->toBe(Band::Strong)
        ->and($score->bandLabel)->toBe('Strong');
});

it('presents in the configured mode, display and headline alike', function (): void {
    config(['edqa.presentation.rounding' => 'truncate']);

    $score = ScoreValue::from(73.67, scoreRules());

    expect($score->display)->toBe('73.6')
        ->and($score->headline)->toBe('73')
        ->and($score->band)->toBe(Band::Review);
});

it('formats en-NG with a grouping separator and always one decimal', function (): void {
    expect(ScoreValue::from(100.0, scoreRules())->display)->toBe('100.0')
        ->and(ScoreValue::from(0.0, scoreRules())->display)->toBe('0.0');
});

it('shows a dash and "No data" when there is no score', function (): void {
    $score = ScoreValue::fromNullable(null, scoreRules());

    expect($score->value)->toBeNull()
        ->and($score->display)->toBe('—')
        ->and($score->headline)->toBe('—')
        ->and($score->band)->toBeNull()
        ->and($score->bandLabel)->toBe(__('scoring.no_data'))
        ->and(__('scoring.no_data'))->toBe('No data');
});

it('passes a real score through fromNullable unchanged', function (): void {
    expect(ScoreValue::fromNullable(92.0, scoreRules()))->toEqual(ScoreValue::from(92.0, scoreRules()));
});

it('translates the band label through the language file', function (): void {
    app('translator')->addLines(['enums.band.needs_action' => 'Action needed (test)'], 'en');

    expect(ScoreValue::from(40.0, scoreRules())->bandLabel)->toBe('Action needed (test)');
});

it('serialises to the one shape the frontend reads (CONVENTION.md §4)', function (): void {
    expect(json_decode(json_encode(ScoreValue::from(92.25, scoreRules()), JSON_THROW_ON_ERROR), true))->toBe([
        'value' => 92.25,
        'display' => '92.3',
        'headline' => '92',
        'band' => 'strong',
        'bandLabel' => 'Strong',
    ])->and(ScoreValue::fromNullable(null, scoreRules())->toArray())->toBe([
        'value' => null,
        'display' => '—',
        'headline' => '—',
        'band' => null,
        'bandLabel' => 'No data',
    ]);
});
