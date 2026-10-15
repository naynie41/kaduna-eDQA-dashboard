<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Round\Actions\CloseRound;
use App\Domain\Round\Actions\CreateRound;
use App\Domain\Round\Actions\ReopenRound;
use App\Domain\Round\Actions\UpdateRoundWindow;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Events\RoundClosed;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\CreateDraftRuleVersion;
use App\Domain\Scoring\Actions\PublishRuleVersion;
use App\Domain\Scoring\Models\RescoreRun;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\ParsedSubmissionBuilder;
use Tests\Support\RescoreFixture;
use Tests\Support\Rows;

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->admin = User::factory()->create();
    $this->v1 = ScoringRuleVersion::query()->where('version', 1)->sole();
});

function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

/** @return array<string, mixed> */
function auditEntry(Round $round, string $event): array
{
    $row = DB::table('activity_log')->where('subject_type', $round->getMorphClass())->where('subject_id', $round->id)->where('event', $event)->sole();

    return ['causer_id' => $row->causer_id, ...json_decode((string) $row->properties, true)];
}

/** Rescore-fixture style items and the nine stored scores, for a round's accepted assessment. */
function scoredAssessment(Round $round, int $version, float $score = 50): int
{
    $f = new RescoreFixture;
    $f->lga = Rows::lga();
    $f->ward = Rows::ward(['lga_id' => $f->lga]);
    $id = $f->assessment($round->id, attributes: ['started_at' => $round->window_start->toDateString().' 09:00:00', 'ended_at' => $round->window_start->toDateString().' 10:00:00']);
    RescoreFixture::scores($id, $version, $score);

    return $id;
}

// ---- create

it('creates an open round for its quarter, audited', function (): void {
    $result = app(CreateRound::class)->execute(2027, 2, day('2027-04-01'), day('2027-06-30'), $this->admin);

    expect($result->round)
        ->year->toBe(2027)
        ->quarter->toBe(2)
        ->status->toBe(RoundStatus::Open)
        ->rule_version_id->toBeNull()
        ->and($result->warnings)->toBe([]);

    $entry = auditEntry($result->round, 'created');
    expect($entry['causer_id'])->toBe($this->admin->id)
        ->and($entry['attributes'])->toEqual(['year' => 2027, 'quarter' => 2, 'window_start' => '2027-04-01', 'window_end' => '2027-06-30', 'status' => 'open']);
});

it('refuses a second round for the same quarter', function (): void {
    Round::factory()->create(['year' => 2027, 'quarter' => 2]);

    expect(fn () => app(CreateRound::class)->execute(2027, 2, day('2027-04-01'), day('2027-06-30'), $this->admin))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.round_exists', ['round' => 'Q2 2027']));
});

it('refuses a window outside its quarter, allowing late visits within the grace period with a warning', function (): void {
    $create = fn (string $start, string $end) => app(CreateRound::class)->execute(2027, 2, day($start), day($end), $this->admin);

    expect(fn () => $create('2027-06-30', '2027-04-01'))->toThrow(RoundActionRefused::class, __('rounds.errors.window_order'));
    expect(fn () => $create('2027-03-31', '2027-06-30'))->toThrow(RoundActionRefused::class, 'Q2 2027');
    expect(fn () => $create('2027-07-01', '2027-07-31'))->toThrow(RoundActionRefused::class, 'Q2 2027');
    expect(fn () => $create('2027-04-01', '2027-08-15'))->toThrow(RoundActionRefused::class, '45'); // 46 days late

    $late = $create('2027-04-01', '2027-08-14'); // 45 days after 30 June: allowed, with a warning
    expect($late->round->window_end->toDateString())->toBe('2027-08-14')
        ->and(array_column($late->warnings, 'code'))->toBe(['window_past_quarter_end'])
        ->and(auditEntry($late->round, 'created')['warnings'])->toBe(['window_past_quarter_end']);

    config(['edqa.rounds.window_grace_days' => 0]);
    expect(fn () => app(CreateRound::class)->execute(2028, 2, day('2028-04-01'), day('2028-07-01'), $this->admin))->toThrow(RoundActionRefused::class);
});

it('refuses a window that overlaps another round, touching days included', function (string $start, string $end): void {
    Round::factory()->create(['year' => 2027, 'quarter' => 2, 'window_start' => '2027-04-01', 'window_end' => '2027-07-20']);

    expect(fn () => app(CreateRound::class)->execute(2027, 3, day($start), day($end), $this->admin))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.window_overlap', ['round' => 'Q2 2027']));
    expect(Round::query()->where(['year' => 2027, 'quarter' => 3])->exists())->toBeFalse();
})->with([
    'starts inside' => ['2027-07-20', '2027-09-30'],
    'covers it' => ['2027-07-01', '2027-09-30'],
]);

it('allows windows that meet without sharing a day', function (): void {
    Round::factory()->create(['year' => 2027, 'quarter' => 2, 'window_start' => '2027-04-01', 'window_end' => '2027-07-20']);

    expect(app(CreateRound::class)->execute(2027, 3, day('2027-07-21'), day('2027-09-30'), $this->admin)->round->id)->toBeInt();
});

// ---- update window

it('moves a window with the same checks, ignoring the round itself, audited old and new', function (): void {
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    Round::factory()->create(['year' => 2027, 'quarter' => 3]);

    $result = app(UpdateRoundWindow::class)->execute($q2, day('2027-04-10'), day('2027-06-30'), $this->admin);
    expect($result->round->window_start->toDateString())->toBe('2027-04-10');

    $entry = auditEntry($q2, 'window_updated');
    expect($entry['old'])->toEqual(['window_start' => '2027-04-01', 'window_end' => '2027-06-30'])
        ->and($entry['attributes'])->toEqual(['window_start' => '2027-04-10', 'window_end' => '2027-06-30'])
        ->and($entry['causer_id'])->toBe($this->admin->id);

    expect(fn () => app(UpdateRoundWindow::class)->execute($q2, day('2027-04-10'), day('2027-07-05'), $this->admin))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.window_overlap', ['round' => 'Q3 2027']));
});

it('refuses a window that would leave accepted visits outside it, but not withdrawn ones', function (): void {
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    Rows::assessment(['round_id' => $q2->id, 'started_at' => '2027-04-05 09:00:00', 'ended_at' => '2027-04-05 10:00:00']);
    Rows::assessment(['round_id' => $q2->id, 'status' => 'withdrawn', 'started_at' => '2027-06-29 09:00:00', 'ended_at' => '2027-06-29 10:00:00']);

    expect(fn () => app(UpdateRoundWindow::class)->execute($q2, day('2027-04-06'), day('2027-06-30'), $this->admin))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.assessments_outside_window', ['count' => 1]));
    expect(app(UpdateRoundWindow::class)->execute($q2, day('2027-04-05'), day('2027-06-20'), $this->admin)->round->window_end->toDateString())
        ->toBe('2027-06-20');
});

it('refuses to move the window of a closed round', function (): void {
    $closed = Round::factory()->closed()->create(['year' => 2027, 'quarter' => 2]);

    expect(fn () => app(UpdateRoundWindow::class)->execute($closed, day('2027-04-02'), day('2027-06-30'), $this->admin))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.round_closed'));
});

// ---- close

it('closes a round: pins the current version, refreshes the view, dispatches RoundClosed, audited', function (): void {
    Event::fake([RoundClosed::class]);
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    scoredAssessment($q2, $this->v1->id);
    expect(DB::selectOne("SELECT ispopulated FROM pg_matviews WHERE matviewname = 'round_aggregates'")->ispopulated)->toBeFalse();

    $closed = app(CloseRound::class)->execute($q2, $this->admin);

    expect($closed)
        ->status->toBe(RoundStatus::Closed)
        ->rule_version_id->toBe($this->v1->id)
        ->closed_at->not->toBeNull()
        ->and(DB::table('round_aggregates')->where(['round_id' => $q2->id, 'scope_type' => 'state', 'owner_type' => 'all', 'level' => 'all'])->value('facility_count'))->toBe(1);
    Event::assertDispatched(RoundClosed::class, fn (RoundClosed $e): bool => $e->roundId === $q2->id && $e->ruleVersionId === $this->v1->id);

    $entry = auditEntry($q2, 'closed');
    expect($entry['causer_id'])->toBe($this->admin->id)
        ->and($entry['old'])->toEqual(['status' => 'open', 'rule_version_id' => null, 'closed_at' => null])
        ->and($entry['attributes']['status'])->toBe('closed')
        ->and($entry['attributes']['rule_version_id'])->toBe($this->v1->id);
});

it('refuses to close a closed round, or one whose assessments are not all scored under the version it would pin', function (): void {
    $closed = Round::factory()->closed()->create(['year' => 2027, 'quarter' => 1]);
    expect(fn () => app(CloseRound::class)->execute($closed, $this->admin))->toThrow(RoundActionRefused::class, __('rounds.errors.already_closed'));

    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    scoredAssessment($q2, $this->v1->id);
    $unscored = Rows::assessment(['round_id' => $q2->id]); // e.g. a rescore still running

    expect(fn () => app(CloseRound::class)->execute($q2, $this->admin))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.not_fully_scored', ['count' => 1]));
    expect($q2->refresh()->status)->toBe(RoundStatus::Open);

    RescoreFixture::scores($unscored, $this->v1->id, 80);
    expect(app(CloseRound::class)->execute($q2, $this->admin)->status)->toBe(RoundStatus::Closed);
});

it("leaves a closed round's scores untouched when a new version is published", function (): void {
    $q1 = Round::factory()->create(['year' => 2027, 'quarter' => 1]);
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    scoredAssessment($q1, $this->v1->id, 50);
    scoredAssessment($q2, $this->v1->id, 50);
    app(CloseRound::class)->execute($q1, $this->admin);
    $before = RescoreFixture::rows($q1->id);
    $dimensions = app(DimensionAggregator::class)->dimensions(new ReportFilter(year: 2027, quarter: 1));

    $draft = app(CreateDraftRuleVersion::class)->execute($this->v1, ['item_weights' => ['availability.item_b' => 3]], $this->admin);
    app(PublishRuleVersion::class)->execute($draft, $this->admin, 'Item b counts triple in availability');

    expect(RescoreFixture::rows($q1->id))->toBe($before)
        ->and(app(RuleVersionForRound::class)->id($q1->refresh()))->toBe($this->v1->id)
        ->and(app(DimensionAggregator::class)->dimensions(new ReportFilter(year: 2027, quarter: 1)))->toEqual($dimensions)
        ->and(RescoreFixture::rows($q2->id, $draft->id))->toHaveCount(9) // the open round moved to v2
        ->and(RescoreRun::query()->pluck('round_id')->all())->toBe([$q2->id]);
});

it('quarantines a submission for a closed round under ROUND_WINDOW', function (): void {
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    app(CloseRound::class)->execute($q2, $this->admin);

    $record = quarantineThrough(ParsedSubmissionBuilder::new()->inRound($q2->refresh())->build());

    $failure = collect($record->failures)->firstWhere('code', 'ROUND_WINDOW');
    expect($failure['detail'])->toBe('visit date 2027-04-14 falls in Q2 2027, which is closed')
        ->and($record->round_id)->toBe($q2->id);
});

// ---- reopen

it('reopens with a reason, audited, keeping the pin; no rescore unless asked', function (): void {
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    scoredAssessment($q2, $this->v1->id);
    app(CloseRound::class)->execute($q2, $this->admin);

    $result = app(ReopenRound::class)->execute($q2, $this->admin, '  Late facility returns from Zaria  ');

    expect($result->round)
        ->status->toBe(RoundStatus::Open)
        ->rule_version_id->toBe($this->v1->id)
        ->reopened_reason->toBe('Late facility returns from Zaria')
        ->closed_at->toBeNull()
        ->and($result->rescore)->toBeNull()
        ->and(RescoreRun::query()->exists())->toBeFalse();

    $entry = auditEntry($q2, 'reopened');
    expect($entry['reason'])->toBe('Late facility returns from Zaria')
        ->and($entry['causer_id'])->toBe($this->admin->id)
        ->and($entry['old']['status'])->toBe('closed')
        ->and($entry['attributes'])->toEqual(['status' => 'open', 'closed_at' => null, 'reopened_reason' => 'Late facility returns from Zaria', 'rescore' => false]);
});

it('rescores on reopen when asked, and always when its pinned version is no longer current', function (): void {
    $q1 = Round::factory()->create(['year' => 2027, 'quarter' => 1]);
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    scoredAssessment($q1, $this->v1->id);
    scoredAssessment($q2, $this->v1->id);
    app(CloseRound::class)->execute($q1, $this->admin);
    app(CloseRound::class)->execute($q2, $this->admin);

    $asked = app(ReopenRound::class)->execute($q2, $this->admin, 'Corrections from the LGA team', rescore: true);
    expect($asked->rescore?->round_id)->toBe($q2->id)
        ->and($asked->rescore?->rule_version_id)->toBe($this->v1->id);

    // v2 is published while q1 stays closed: reopening it reads v2, so it must be rescored.
    $draft = app(CreateDraftRuleVersion::class)->execute($this->v1, ['bands' => ['strong' => 95, 'acceptable' => 85, 'review' => 75]], $this->admin);
    app(PublishRuleVersion::class)->execute($draft, $this->admin, 'Stricter bands from the client');
    $forced = app(ReopenRound::class)->execute($q1, $this->admin, 'Corrections from the LGA team');

    expect($forced->rescore?->rule_version_id)->toBe($draft->id)
        ->and(RescoreFixture::rows($q1->id, $draft->id))->toHaveCount(9)
        ->and(auditEntry($q1, 'reopened')['attributes']['rescore'])->toBeTrue();
});

it('refuses to reopen without a reason of at least 10 characters, or an open round', function (string $reason): void {
    $closed = Round::factory()->closed()->create(['year' => 2027, 'quarter' => 1]);

    expect(fn () => app(ReopenRound::class)->execute($closed, $this->admin, $reason))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.reason_too_short'));
    expect($closed->refresh()->status)->toBe(RoundStatus::Closed);
})->with(['', '   short   ']);

it('refuses to reopen a round that is open', function (): void {
    $open = Round::factory()->create(['year' => 2027, 'quarter' => 1]);

    expect(fn () => app(ReopenRound::class)->execute($open, $this->admin, 'Reason that is long enough'))
        ->toThrow(RoundActionRefused::class, __('rounds.errors.not_closed'));
});

it('writes no model audit entry beside the explicit ones', function (): void {
    $result = app(CreateRound::class)->execute(2027, 2, day('2027-04-01'), day('2027-06-30'), $this->admin);
    app(CloseRound::class)->execute($result->round, $this->admin);
    app(ReopenRound::class)->execute($result->round, $this->admin, 'Late facility returns from Zaria');

    expect(DB::table('activity_log')->where('subject_type', $result->round->getMorphClass())->where('subject_id', $result->round->id)->orderBy('id')->pluck('event')->all())
        ->toBe(['created', 'closed', 'reopened'])
        ->and(QuarantinedRecord::query()->exists())->toBeFalse();
});
