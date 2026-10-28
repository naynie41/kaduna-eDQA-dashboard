<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Jobs\RequestRescoreJob;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Scoring\ScoreCalculator;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

// Correcting an accepted assessment (SECURITY.md §4, ARCHITECTURE.md §7, D-58): item results and
// visit times only, with a reason; scores are always recomputed by ScoreCalculator, never taken
// from input (CLAUDE.md rule 2).

beforeEach(function (): void {
    $this->version = ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Amina Bello']);
    $this->round = Round::factory()->create(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
    $this->facility = Facility::factory()->create();
});

/** An assessment accepted through the real pipeline: every slot 75 (reporting_rate fails). */
function acceptedAssessment(Facility $facility, Round $round, ?ParsedSubmissionBuilder $builder = null): Assessment
{
    $parsed = ($builder ?? ParsedSubmissionBuilder::new())->forFacility($facility)->inRound($round)->build();
    app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]]);

    return Assessment::query()->where('facility_id', $facility->id)->where('round_id', $round->id)->sole();
}

/** @return array<string, float> "dimension:slot" => score under the version */
function storedScores(Assessment $assessment, int $versionId): array
{
    return DB::table('assessment_scores')->where('assessment_id', $assessment->id)->where('rule_version_id', $versionId)->get()
        ->mapWithKeys(fn (object $row): array => ["{$row->dimension}:{$row->month_slot}" => (float) $row->score])->all();
}

function correct(mixed $test, Assessment $assessment, array $body): TestResponse
{
    return $test->actingAs($test->admin)->postJson("/api/assessments/{$assessment->id}/corrections", [...$body, 'reason' => $body['reason'] ?? 'Supervisor re-checked the register']);
}

it('is behind the sign-in', function (): void {
    $this->postJson('/api/assessments/1/corrections', ['reason' => 'A long enough reason'])->assertUnauthorized();
});

// ---- Scores come only from the calculator

it('recomputes the scores from the corrected items through ScoreCalculator, and writes no score of its own', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    expect(storedScores($assessment, $this->version->id)['availability:2'])->toBe(75.0);

    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        // Statements that write assessment_scores, however they are phrased.
        if (preg_match('/\b(insert\s+into|update|delete\s+from)\s+"?assessment_scores"?/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    correct($this, $assessment, ['items' => ['avail_m2_reporting_rate' => 'pass']])->assertOk()
        ->assertJsonPath('data.changes.0', ['key' => 'avail_m2_reporting_rate', 'old' => 'fail', 'new' => 'pass']);

    // The spy: every score write is RescoreAssessments' calculator-fed upsert (ScoreCalculator is final).
    // (The test queue is synchronous, so the round's rescore runs too: the same upsert again.)
    expect($writes)->not->toBeEmpty();
    foreach ($writes as $sql) {
        expect($sql)->toContain('WITH incoming (assessment_id, dimension, month_slot, score)');
    }

    // And what is stored is exactly what the calculator derives from the corrected items.
    $rules = RuleConfig::fromVersion($this->version);
    $items = ItemResponse::query()->where('assessment_id', $assessment->id)->orderBy('id')->get()
        ->map(fn (ItemResponse $r): ItemResponseData => new ItemResponseData($r->dimension, $r->month_slot, (string) $r->item_code, $rules->outcome((string) $r->value), $r->is_applicable))->all();
    $card = app(ScoreCalculator::class)->calculate($items, $rules);

    $stored = storedScores($assessment, $this->version->id);
    expect($stored['availability:2'])->toBe(100.0)
        ->and($stored['availability:1'])->toBe(75.0);
    foreach ($card->slots as $dimension => $months) {
        foreach ($months as $slot => $score) {
            expect($stored["{$dimension}:{$slot}"])->toBe(round((float) $score, 2));
        }
    }
});

it('refuses a request that carries a score, and changes nothing', function (array $body): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    $before = storedScores($assessment, $this->version->id);

    correct($this, $assessment, $body)->assertUnprocessable();

    expect(storedScores($assessment, $this->version->id))->toBe($before);
})->with([
    'scores' => [['items' => ['avail_m2_reporting_rate' => 'pass'], 'scores' => ['availability:2' => 100]]],
    'score' => [['items' => ['avail_m2_reporting_rate' => 'pass'], 'score' => 100]],
    'overall' => [['overall' => 100]],
]);

// ---- Audit

it('audits old and new per field, with the reason and the actor', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    $start = $assessment->started_at;

    correct($this, $assessment, [
        'items' => ['avail_m2_reporting_rate' => 'pass', 'valid_m1_tally_sheet' => 'na'],
        'started_at' => $start->addMinutes(10)->toIso8601String(),
        'ended_at' => $start->addMinutes(70)->toIso8601String(),
        'reason' => 'Supervisor re-checked the register',
    ])->assertOk();

    $itemEntries = Activity::query()->where('event', 'corrected')->where('subject_type', (new ItemResponse)->getMorphClass())->orderBy('id')->get();
    expect($itemEntries)->toHaveCount(2)
        ->and($itemEntries[0]->properties['old'])->toBe(['value' => 'fail'])
        ->and($itemEntries[0]->properties['attributes'])->toBe(['value' => 'pass'])
        ->and($itemEntries[1]->properties['old'])->toBe(['value' => 'pass'])
        ->and($itemEntries[1]->properties['attributes'])->toBe(['value' => 'na'])
        ->and($itemEntries[0]->properties['reason'])->toBe('Supervisor re-checked the register')
        ->and($itemEntries[0]->causer_id)->toBe($this->admin->id);

    $times = Activity::query()->where('event', 'corrected')->where('subject_type', (new Assessment)->getMorphClass())->sole();
    // jsonb keeps its own key order: compare as maps.
    expect($times->properties['old'])->toEqual(['started_at' => $start->toIso8601String(), 'ended_at' => $assessment->ended_at->toIso8601String()])
        ->and($times->properties['attributes'])->toEqual(['started_at' => $start->addMinutes(10)->toIso8601String(), 'ended_at' => $start->addMinutes(70)->toIso8601String()])
        ->and($times->properties['reason'])->toBe('Supervisor re-checked the register')
        ->and($times->causer_id)->toBe($this->admin->id);

    $tally = ItemResponse::query()->where('assessment_id', $assessment->id)->where('item_code', 'tally_sheet')->where('dimension', 'validity')->where('month_slot', 1)->sole();
    expect($tally->value)->toBe('na')->and($tally->is_applicable)->toBeFalse();
});

// ---- The round's rescore

it('queues the round\'s rescore as a correction', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    Queue::fake([RequestRescoreJob::class]);

    correct($this, $assessment, ['items' => ['avail_m2_reporting_rate' => 'pass']])->assertOk()
        ->assertJsonPath('data.rescore_round', ['id' => $this->round->id, 'year' => 2026, 'quarter' => 2]);

    Queue::assertPushed(RequestRescoreJob::class, fn (RequestRescoreJob $job): bool => $job->roundId === $this->round->id && $job->trigger === RescoreTrigger::Correction);
});

// ---- Soft rules after the rescore

it('raises and clears soft flags after scoring: a perfect assessment, then a short visit', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    expect($assessment->flags)->toBe([])->and($assessment->status)->toBe('accepted');

    $allPass = [];
    foreach (['avail', 'consist', 'valid'] as $prefix) {
        foreach ([1, 2, 3] as $slot) {
            $allPass["{$prefix}_m{$slot}_reporting_rate"] = 'pass';
        }
    }
    correct($this, $assessment, ['items' => $allPass])->assertOk()->assertJsonPath('data.flags', ['ALL_PERFECT'])->assertJsonPath('data.status', 'flagged');

    $start = $assessment->started_at;
    correct($this, $assessment, ['items' => ['avail_m1_reporting_rate' => 'fail'], 'ended_at' => $start->addMinutes(12)->toIso8601String()])->assertOk()
        ->assertJsonPath('data.flags', ['VISIT_TOO_SHORT']);

    correct($this, $assessment, ['ended_at' => $start->addMinutes(50)->toIso8601String()])->assertOk()
        ->assertJsonPath('data.flags', [])->assertJsonPath('data.status', 'accepted');
});

it('never raises again a flag an administrator overrode', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round, ParsedSubmissionBuilder::new()->withVisitMinutes(12));
    expect($assessment->flags)->toBe(['VISIT_TOO_SHORT']);
    $this->actingAs($this->admin)->postJson("/api/assessments/{$assessment->id}/flags/VISIT_TOO_SHORT/override", ['reason' => 'Visit time confirmed with the supervisor'])->assertOk();

    correct($this, $assessment, ['ended_at' => $assessment->started_at->addMinutes(11)->toIso8601String()])->assertOk()
        ->assertJsonPath('data.flags', []);
});

// ---- Refusals

it('refuses a closed round with a clear message, and changes nothing', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    $this->round->update(['status' => 'closed', 'rule_version_id' => $this->version->id]);
    $audits = Activity::query()->count();

    correct($this, $assessment, ['items' => ['avail_m2_reporting_rate' => 'pass']])
        ->assertStatus(409)
        ->assertJson(['code' => 'round_closed', 'message' => __('assessments.corrections.errors.round_closed', ['round' => 'Q2 2026'])]);

    expect(storedScores($assessment, $this->version->id)['availability:2'])->toBe(75.0)
        ->and(ItemResponse::query()->where('assessment_id', $assessment->id)->where('value', 'pass')->where('item_code', 'reporting_rate')->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($audits);
});

it('refuses item codes that are not this assessment\'s, and values that are not results', function (array $items, string $error): void {
    $assessment = acceptedAssessment($this->facility, $this->round);

    correct($this, $assessment, ['items' => $items])->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'unknown item' => [['avail_m2_not_an_item' => 'pass'], 'items.avail_m2_not_an_item'],
    'no such slot' => [['avail_m4_reporting_rate' => 'pass'], 'items.avail_m4_reporting_rate'],
    'not a key' => [['availability.2.reporting_rate' => 'pass'], 'items'],
    'not a result' => [['avail_m2_reporting_rate' => 'maybe'], 'items.avail_m2_reporting_rate'],
    'raw choice' => [['avail_m2_reporting_rate' => 'yes'], 'items.avail_m2_reporting_rate'],
]);

it('refuses an item of another assessment', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    $other = acceptedAssessment(Facility::factory()->create(), $this->round, ParsedSubmissionBuilder::new()->withItemValue(Dimension::Availability, 1, 'summary_form', 'fail'));
    ItemResponse::query()->where('assessment_id', $assessment->id)->where('item_code', 'summary_form')->delete();

    correct($this, $assessment, ['items' => ['avail_m1_summary_form' => 'pass']])->assertUnprocessable()->assertJsonValidationErrors('items.avail_m1_summary_form');
    expect(ItemResponse::query()->where('assessment_id', $other->id)->where('item_code', 'summary_form')->where('dimension', 'availability')->where('month_slot', 1)->sole()->value)->toBe('fail');
});

it('validates the request: a reason, something to correct, times in order', function (array $body, string $field): void {
    $assessment = acceptedAssessment($this->facility, $this->round);

    $this->actingAs($this->admin)->postJson("/api/assessments/{$assessment->id}/corrections", $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no reason' => [['items' => ['avail_m2_reporting_rate' => 'pass']], 'reason'],
    'short reason' => [['items' => ['avail_m2_reporting_rate' => 'pass'], 'reason' => 'short'], 'reason'],
    'nothing to correct' => [['reason' => 'Supervisor re-checked the register'], 'items'],
    'end before start' => [['started_at' => '2026-04-14T10:00:00+01:00', 'ended_at' => '2026-04-14T09:00:00+01:00', 'reason' => 'Supervisor re-checked the register'], 'ended_at'],
    'no offset' => [['started_at' => '2026-04-14 10:00', 'reason' => 'Supervisor re-checked the register'], 'started_at'],
]);

it('refuses what the action catches: no change, a visit moved out of its round, an unscorable result', function (array $body, string $code): void {
    $assessment = acceptedAssessment($this->facility, $this->round);
    $before = storedScores($assessment, $this->version->id);

    correct($this, $assessment, $body)->assertStatus(409)->assertJsonPath('code', $code);

    expect(storedScores($assessment, $this->version->id))->toBe($before)
        ->and(Activity::query()->where('event', 'corrected')->count())->toBe(0);
})->with([
    'the same value' => [['items' => ['avail_m2_reporting_rate' => 'fail']], 'no_changes'],
    'start outside the round' => [['started_at' => '2026-07-02T09:00:00+01:00', 'ended_at' => '2026-07-02T10:00:00+01:00'], 'outside_round'],
    'end before the stored start' => [['ended_at' => '2026-04-01T00:00:00+01:00'], 'end_before_start'],
    'a whole dimension N/A' => [['items' => [
        'avail_m1_register_present' => 'na', 'avail_m1_summary_form' => 'na', 'avail_m1_tally_sheet' => 'na', 'avail_m1_reporting_rate' => 'na',
        'avail_m2_register_present' => 'na', 'avail_m2_summary_form' => 'na', 'avail_m2_tally_sheet' => 'na', 'avail_m2_reporting_rate' => 'na',
        'avail_m3_register_present' => 'na', 'avail_m3_summary_form' => 'na', 'avail_m3_tally_sheet' => 'na', 'avail_m3_reporting_rate' => 'na',
    ]], 'unscorable'],
]);

// ---- What the drawer needs

it('gives the drawer what correction mode needs: the round open, the times and each item\'s key', function (): void {
    $assessment = acceptedAssessment($this->facility, $this->round);

    $this->actingAs($this->admin)->getJson("/api/facilities/{$this->facility->id}?round={$this->round->id}")->assertOk()
        ->assertJsonPath('data.round.open', true)
        ->assertJsonPath('data.assessment.started_at', $assessment->started_at->toIso8601String())
        ->assertJsonPath('data.assessment.ended_at', $assessment->ended_at->toIso8601String())
        ->assertJsonPath('data.items.0.items.0.key', 'avail_m1_register_present');

    $this->round->update(['status' => 'closed', 'rule_version_id' => $this->version->id]);
    $this->actingAs($this->admin)->getJson("/api/facilities/{$this->facility->id}?round={$this->round->id}")->assertJsonPath('data.round.open', false);
});
