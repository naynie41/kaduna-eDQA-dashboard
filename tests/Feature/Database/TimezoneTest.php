<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ParsedSubmissionBuilder;

// Laravel writes timestamps without an offset, so the Postgres session must run in the app's
// time zone or every stored time shifts (a 23:30 Lagos visit would land on the next day).

it('runs the database session in the app time zone', function (): void {
    expect(DB::selectOne('SHOW timezone')->TimeZone)->toBe(config('app.timezone'));
});

it('stores a late-evening local time as the same instant and the same local day', function (): void {
    $lateEvening = CarbonImmutable::parse('2026-04-14 23:30', 'Africa/Lagos');
    $assessment = Assessment::factory()->create(['started_at' => $lateEvening, 'ended_at' => $lateEvening->addHour()]);

    $row = DB::selectOne("SELECT started_at AT TIME ZONE 'Africa/Lagos' AS local, extract(epoch FROM started_at)::bigint AS epoch FROM assessments WHERE id = ?", [$assessment->id]);

    expect($row->local)->toBe('2026-04-14 23:30:00')
        ->and((int) $row->epoch)->toBe($lateEvening->getTimestamp())
        ->and($assessment->refresh()->started_at->equalTo($lateEvening))->toBeTrue();
});

it('keeps the instant of a visit time ODK sent with another offset', function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);
    // 22:30 UTC is 23:30 in Lagos.
    $start = CarbonImmutable::parse('2026-04-14T22:30:00+00:00');
    $parsed = ParsedSubmissionBuilder::new()->inRound($round)->withStartedAt($start)->withEndedAt($start->addMinutes(45))->build();

    app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]]);

    $assessment = Assessment::query()->sole();
    expect($assessment->started_at->getTimestamp())->toBe($start->getTimestamp())
        ->and($assessment->ended_at->getTimestamp())->toBe($start->addMinutes(45)->getTimestamp())
        ->and(DB::selectOne("SELECT (started_at AT TIME ZONE 'Africa/Lagos')::date::text AS day FROM assessments")->day)->toBe('2026-04-14');
});
