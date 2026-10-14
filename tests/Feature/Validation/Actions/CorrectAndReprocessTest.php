<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->facility = Facility::factory()->create();
    $this->facility->load('lga');
});

const GOOD_REASON = 'Assessor confirmed the LGA by phone';

it('accepts a null-LGA submission once the LGA is corrected, creating its scores', function (): void {
    Event::fake([QuarantineResolved::class]);
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());

    $outcome = app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);

    $record->refresh();
    expect($outcome->status)->toBe(SubmissionStatus::Accepted)
        ->and($record->status)->toBe(QuarantineStatus::Resolved)
        ->and($record->resolution)->toBe(QuarantineResolution::Reprocessed)
        ->and($record->resolved_by)->toBe($this->admin->id)
        ->and($record->resolved_at)->not->toBeNull()
        ->and($record->resolution_note)->toBe(GOOD_REASON)
        ->and($record->corrections)->toBe(['lga_ref' => $this->facility->lga->code])
        ->and(Assessment::query()->sole()->facility_id)->toBe($this->facility->id)
        ->and(AssessmentScore::query()->count())->toBe(9)
        ->and($record->submission->status)->toBe(SubmissionStatus::Accepted);

    Event::assertDispatched(QuarantineResolved::class, fn (QuarantineResolved $e): bool => $e->quarantinedRecordId === $record->id && $e->resolution === QuarantineResolution::Reprocessed);
});

it('never touches the raw payload or the parsed snapshot', function (): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());
    $before = DB::table('quarantined_records')->where('id', $record->id)->first(['payload', 'parsed']);
    $submissionPayload = DB::table('submissions')->where('id', $record->submission_id)->value('payload');

    app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);

    $after = DB::table('quarantined_records')->where('id', $record->id)->first(['payload', 'parsed']);
    expect($after->payload)->toBe($before->payload)
        ->and($after->parsed)->toBe($before->parsed)
        ->and(DB::table('submissions')->where('id', $record->submission_id)->value('payload'))->toBe($submissionPayload);
});

it('writes one audit entry with the old and new corrections and the reason', function (): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());

    app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);

    $entry = Activity::query()->forSubject($record)->where('event', '!=', 'created')->sole();
    expect($entry->event)->toBe('corrected')
        ->and($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['reason'])->toBe(GOOD_REASON)
        ->and($entry->properties['old'])->toMatchArray(['corrections' => [], 'status' => 'open'])
        ->and($entry->properties['attributes'])->toMatchArray([
            'corrections' => ['lga_ref' => $this->facility->lga->code],
            'status' => 'resolved',
            'resolution' => 'reprocessed',
        ]);
});

it('keeps the record open with the new failures when the correction does not fix it', function (): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->withWard('Quarterly')->build());

    $outcome = app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);

    $record->refresh();
    expect($outcome->status)->toBe(SubmissionStatus::Quarantined)
        ->and($record->status)->toBe(QuarantineStatus::Open)
        ->and(array_column($record->failures, 'code'))->toBe(['COLUMN_DRIFT'])
        ->and($record->corrections)->toBe(['lga_ref' => $this->facility->lga->code])
        ->and(Assessment::query()->count())->toBe(0)
        ->and(Activity::query()->forSubject($record)->where('event', '!=', 'created')->sole()->properties['attributes']['status'])->toBe('open');
});

it('keeps earlier corrections and re-derives a corrected start time', function (): void {
    $parsed = ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->withRawStart('FEBRUARY')->withStartedAt(null)->build();
    $record = quarantineThrough($parsed);
    $start = $parsed->endedAt->subMinutes(45)->format('Y-m-d\TH:i:s.vP');

    app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);
    $outcome = app(CorrectAndReprocess::class)->execute($record->refresh(), ['raw_start' => $start], $this->admin, 'Start time read from the paper form');

    expect($outcome->status)->toBe(SubmissionStatus::Accepted)
        ->and($record->refresh()->corrections)->toBe(['lga_ref' => $this->facility->lga->code, 'raw_start' => $start])
        ->and(Assessment::query()->sole()->started_at->equalTo($parsed->endedAt->subMinutes(45)))->toBeTrue();
});

it('refuses a reason shorter than 10 characters', function (string $reason): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());

    expect(fn () => app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => 'X'], $this->admin, $reason))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.reason_too_short'));
    expect($record->refresh()->corrections)->toBe([]);
})->with(['', 'too short', '         .']);

it('refuses to correct anything but the whitelisted fields', function (string $field, mixed $value): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());

    expect(fn () => app(CorrectAndReprocess::class)->execute($record, [$field => $value], $this->admin, GOOD_REASON))
        ->toThrow(QuarantineActionRefused::class);
    expect($record->refresh()->corrections)->toBe([])
        ->and(Activity::query()->forSubject($record)->where('event', '!=', 'created')->count())->toBe(0);
})->with([
    'items' => ['items', []],
    'typed scores' => ['raw_numeric_scores', ['availability_m1' => 90]],
    'form version' => ['form_version', '2026.1'],
    'a score' => ['score', 90],
]);

it('refuses a correction of the wrong type', function (string $field, mixed $value): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());

    expect(fn () => app(CorrectAndReprocess::class)->execute($record, [$field => $value], $this->admin, GOOD_REASON))
        ->toThrow(QuarantineActionRefused::class);
})->with([
    'quarter as text' => ['round_quarter', 'two'],
    'quarter out of range' => ['round_quarter', 5],
    'start as null' => ['raw_start', null],
    'lga as a number' => ['lga_ref', 12],
]);

it('refuses a record that is already resolved', function (): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());
    app(CorrectAndReprocess::class)->execute($record, ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);

    expect(fn () => app(CorrectAndReprocess::class)->execute($record->refresh(), ['lga_ref' => 'X'], $this->admin, GOOD_REASON))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.already_resolved'));
});
