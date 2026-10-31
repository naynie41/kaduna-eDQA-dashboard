<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\ScoreCalculator;
use Database\Seeders\ScoringRuleVersionSeeder;

// The real KoboToolbox form, end to end through the normaliser, the field map and the item rules
// (D-65–D-71): one real submission from the asset, anonymised (every free-text, date, location and
// device answer, every identifier and the account replaced; the scored answers and the form's own
// score fields kept). The portal must score it exactly as the form scored itself: this visit has no
// unanswered consistency item, so D-67's deliberate difference does not apply.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    CurrentRuleVersion::forget();
    $this->raw = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/kobo/real/monthly-visit-september.json')), true, flags: JSON_THROW_ON_ERROR);
});

it('reads the real form\'s monthly visit: identity, its round and its one month slot', function (): void {
    $parsed = koboParse($this->raw);

    expect($parsed->formVersionKnown)->toBeTrue()
        ->and($parsed->formVersion)->toBe('vBnJSJRKjVuT4yDaRhKXye')
        ->and([$parsed->roundYear, $parsed->roundQuarter, $parsed->monthSlots])->toBe([2026, 3, [3]]) // September
        ->and($parsed->facilityRef)->not->toBeNull()
        ->and($parsed->lgaRef)->not->toBeNull()
        ->and($parsed->startedAt)->not->toBeNull()
        ->and($parsed->endedAt)->not->toBeNull()
        ->and($parsed->assessorName)->toBe('Assessor A')
        ->and($parsed->outOfScope)->toBeNull()
        ->and(collect($parsed->items)->where('value', ItemResponseData::INVALID))->toBeEmpty()
        ->and(collect($parsed->items)->pluck('monthSlot')->unique()->values()->all())->toBe([3]);
});

it('scores the real visit exactly as the form scored itself, to two decimals', function (): void {
    $parsed = koboParse($this->raw);
    $card = app(ScoreCalculator::class)->calculate($parsed->items, app(CurrentRuleVersion::class)->config());

    expect(round($card->dimensions['availability'], 2))->toBe((float) $this->raw['availability_score_pct'])
        ->and(round($card->dimensions['consistency'], 2))->toBe((float) $this->raw['consistency_score_pct'])
        ->and(round($card->dimensions['validity'], 2))->toBe((float) $this->raw['validity_score_pct'])
        ->and(round($card->overall, 2))->toBe((float) $this->raw['overall_dqa_score_pct']);
});
