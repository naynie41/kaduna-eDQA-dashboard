<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// Implementation plan, read-only (ARCHITECTURE.md §9.2.6). ReportingFixture, 2026 Q2 (bands
// strong 90, acceptable 80, review 70):
//   Alpha    overall 78.9  availability 78.3  consistency 73.3  validity 85.0  → action: consistency
//   Bravo    overall 76.0  availability 70.0  consistency 77.5  validity 80.5  → action: availability
//   Charlie  overall 81.0                                                     → no action needed

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

/** The plan prop after its deferred load. */
function planFor(object $test, string $query = '?year=2026&quarter=2'): array
{
    $result = null;
    $test->actingAs($test->admin)->get("/dashboard/plan{$query}")
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$result): void {
            $page->component('dashboard/plan')
                ->missing('plan')
                ->loadDeferredProps(function (Assert $reload) use (&$result): void {
                    $result = $reload->toArray()['props']['plan'];
                });
        });

    return $result;
}

/** [name, dimension, score display] for each action row. */
function actionRows(array $plan): array
{
    return array_map(fn (array $r): array => [$r['name'], $r['dimension'], $r['score']['display']], $plan['actions']);
}

it('gives every LGA below Acceptable an action on its weakest dimension, weakest LGA first', function (): void {
    $plan = planFor($this);

    expect(actionRows($plan))->toBe([
        ['Bravo', 'availability', '70.0'],
        ['Alpha', 'consistency', '73.3'],
    ])
        ->and($plan['actions'][0])->toMatchArray([
            'action' => 'Restock HMIS tools and confirm the current register set is in place',
            'owner' => 'LGA M&E officer',
            'reviewRound' => 'Q2 2026',
            'reviewDate' => null,
            'stored' => false,
        ])
        ->and($plan['actions'][0]['overall'])->toMatchArray(['display' => '76.0', 'band' => 'review'])
        ->and($plan['actions'][1]['owner'])->toBe('State M&E team')
        ->and(array_map(fn (array $c): array => [$c['name'], $c['overall']['display']], $plan['clear']))->toBe([['Charlie', '81.0']])
        ->and($plan['round'])->toBe('Q2 2026')
        ->and($plan['threshold'])->toBe(['band' => 'acceptable', 'label' => 'Acceptable', 'value' => '80']);
});

it('counts actions by type on the server, every dimension listed with its meaning', function (): void {
    $summary = planFor($this)['summary'];

    expect(array_map(fn (array $s): array => [$s['dimension'], $s['count'], $s['owner']], $summary))->toBe([
        ['availability', 1, 'LGA M&E officer'],
        ['consistency', 1, 'State M&E team'],
        ['validity', 0, 'Programme manager'],
    ])
        ->and($summary[2]['action'])->toBe('Retraining on data element definitions and correct tool completion')
        ->and($summary[1]['meaning'])->toBe('The summary form does not agree with the register.');
});

it('takes the threshold from the rule version, not from code', function (): void {
    $config = ReportingFixture::CONFIG;
    $config['bands'] = ['strong' => 90, 'acceptable' => 77, 'review' => 70];
    DB::table('scoring_rule_versions')->where('id', $this->f->v2)->update(['config' => json_encode($config)]);
    CurrentRuleVersion::forget();

    $plan = planFor($this);

    expect(actionRows($plan))->toBe([['Bravo', 'availability', '70.0']])
        ->and(array_column($plan['clear'], 'name'))->toBe(['Alpha', 'Charlie'])
        ->and($plan['threshold']['value'])->toBe('77');
});

it('acts below another band when configured', function (): void {
    config(['edqa.plan.action_below' => 'strong']);

    $plan = planFor($this);

    expect(array_column($plan['actions'], 'name'))->toBe(['Bravo', 'Alpha', 'Charlie'])
        ->and($plan['clear'])->toBe([])
        ->and($plan['threshold'])->toMatchArray(['band' => 'strong', 'label' => 'Strong', 'value' => '90']);
});

it('breaks a tie between dimensions in alphabetical order', function (): void {
    foreach (['DDD' => [[60, 60, 60], [60, 60, 60], [60, 60, 60]], 'EEE' => [[70, 70, 70], [60, 60, 60], [60, 60, 60]]] as $code => [$a, $c, $v]) {
        $lga = Rows::lga(['code' => $code, 'name' => "LGA {$code}"]);
        $facility = Rows::facility(['code' => "KD-{$code}", 'lga_id' => $lga, 'ward_id' => Rows::ward(['lga_id' => $lga])]);
        ReportingFixture::scores(Rows::assessment(['round_id' => $this->f->q2, 'facility_id' => $facility]), $this->f->v2, $a, $c, $v);
    }

    $byName = collect(planFor($this)['actions'])->keyBy('name');

    expect($byName['LGA DDD']['dimension'])->toBe('availability') // all three equal
        ->and($byName['LGA EEE']['dimension'])->toBe('consistency'); // consistency = validity
});

it('shows a stored plan instead of the generated action, for that round only', function (): void {
    $alpha = $this->f->lgas['AAA'];
    Rows::planAction(['round_id' => $this->f->q2, 'lga_id' => $alpha, 'dimension' => 'validity', 'action_text' => 'Peer review of tally sheets', 'assigned_to' => 'Dr A. Bello', 'due_date' => '2026-09-30']);
    // Charlie needs no action, but a stored row is the plan: it is listed.
    Rows::planAction(['round_id' => $this->f->q2, 'lga_id' => $this->f->lgas['CCC'], 'dimension' => 'consistency', 'action_text' => 'Follow-up visit', 'assigned_to' => 'State M&E team', 'due_date' => '2026-08-15']);
    // Another round's plan is not this round's.
    Rows::planAction(['round_id' => $this->f->q1, 'lga_id' => $this->f->lgas['BBB'], 'dimension' => 'validity', 'action_text' => 'Old', 'assigned_to' => 'Nobody', 'due_date' => '2026-03-01']);

    $plan = planFor($this);
    $byName = collect($plan['actions'])->keyBy('name');

    expect(actionRows($plan))->toBe([
        ['Bravo', 'availability', '70.0'],
        ['Alpha', 'validity', '85.0'],
        ['Charlie', 'consistency', '81.0'], // Charlie: consistency 81.0 (88, 74)
    ])
        ->and($byName['Alpha'])->toMatchArray(['action' => 'Peer review of tally sheets', 'owner' => 'Dr A. Bello', 'reviewDate' => '2026-09-30', 'reviewRound' => null, 'stored' => true])
        ->and($byName['Bravo']['stored'])->toBe(false)
        ->and($plan['clear'])->toBe([])
        ->and(array_column($plan['summary'], 'count'))->toBe([1, 1, 1]);
});

it('has no plan when no round is in view', function (): void {
    // The page never asks for such a year (ShareDashboardContext cleans it): ask the aggregator.
    $plan = app(ImplementationPlanAggregator::class)->plan(new ReportFilter(year: 2030));

    expect($plan->round)->toBeNull()
        ->and($plan->actions)->toBe([])
        ->and($plan->clear)->toBe([])
        ->and(array_map(fn ($s) => $s->count, $plan->summary))->toBe([0, 0, 0]);
});
