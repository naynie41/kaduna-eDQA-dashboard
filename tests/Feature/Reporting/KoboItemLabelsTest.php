<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Tests\Support\ParsedSubmissionBuilder;

// The real Kobo form's question wording (lang/en/items.php, generated from the form by
// discovery/kobo_item_labels.php): the facility drawer names each item by what the assessor was
// asked, not by its code. The wording is the client's own, taken from the form as deployed.

it('has the form\'s wording for every scored item of the real Kobo form', function (): void {
    $missing = [];
    foreach (config('edqa_item_rules')['kobo-2024010101'] as $rule) {
        $label = __("items.{$rule['dimension']}.{$rule['code']}");
        if (! is_string($label) || $label === "items.{$rule['dimension']}.{$rule['code']}" || trim($label) === '') {
            $missing[] = "{$rule['dimension']}.{$rule['code']}";
        }
    }

    expect($missing)->toBe([]);
});

it('keeps the wording plain: no markup, no form placeholders, no doubled spaces', function (): void {
    $bad = [];
    foreach (trans('items') as $dimension => $labels) {
        foreach ($labels as $code => $label) {
            if (preg_match('/<[^>]+>|\$\{|\s{2,}|^\s|\s$/', $label) === 1) {
                $bad[] = "{$dimension}.{$code}: {$label}";
            }
        }
    }

    expect($bad)->toBe([]);
});

it('words each kind of item as the form asks it', function (): void {
    expect(__('items.availability.m__1a'))->toBe("1a. Is month's site-copy of the NHMIS monthly summary form available?")
        ->and(__('items.consistency.m__1a_21'))->toStartWith('1a. How many of the three clients had their age and sex correctly transcribed')
        // A validity item is three numbers compared: named by the data element they count.
        ->and(__('items.validity.m_dhis_1_m_msf_1_m_register_1'))->toBe('Health Facility attendance for aged 12-59 months Males');
});

it('shows the wording in the facility drawer', function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 3, 'window_start' => '2026-07-01', 'window_end' => '2026-10-31']);
    $facility = Facility::factory()->create();
    $parsed = ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)
        ->withChecks(Dimension::Consistency, 1, 'm__1a_21', ['pass', 'pass', 'fail'])->build();
    app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]]);

    $items = $this->actingAs(User::factory()->withTwoFactor()->create())->getJson("/api/facilities/{$facility->id}?round={$round->id}")->assertOk()->json('data.items');
    $item = collect($items)->firstWhere(fn (array $g): bool => $g['dimension'] === 'consistency' && $g['slot'] === 1)['items'];

    expect(collect($item)->firstWhere('code', 'm__1a_21')['label'])->toStartWith('1a. How many of the three clients');
});
