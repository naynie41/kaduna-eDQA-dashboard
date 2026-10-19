<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Odk\SubmissionParser;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\ValidationContextFactory;
use App\Domain\Validation\ValidationPipeline;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BrokenSubmissions;
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

it('rebuilds from the raw payload exactly as from the parsed snapshot, for every broken fixture', function (string $name): void {
    [$parsed, $expected] = BrokenSubmissions::build($name);
    $record = quarantineThrough($parsed);

    $fromPayload = app(SubmissionParser::class)->parse($record->submission->payload);
    $context = app(ValidationContextFactory::class)->forBatch([$fromPayload]);
    $codes = array_map(fn (RuleFailure $f): string => $f->code, app(ValidationPipeline::class)->validate($fromPayload, $context)->failures());

    expect($codes)->toBe($expected)
        ->and(array_column($record->failures, 'code'))->toBe($expected);
    // An unknown form version is never read past its identity, so only its outcome can agree.
    if ($parsed->formVersionKnown) {
        // jsonb keeps keys in its own order, so items parse back in another order: compare them as a set.
        // A builder that moved started_at / ended_at alone left raw strings no parser would pair with
        // them; the payload carries the times, so only the raw text can differ there.
        $raw = fn (string $field, string $time): bool => $record->parsed[$time] === null || CarbonImmutable::parse($record->parsed[$field])->equalTo(CarbonImmutable::parse($record->parsed[$time]));
        $keepRaw = $raw('raw_start', 'started_at') && $raw('raw_end', 'ended_at');
        $sorted = function (array $parsed) use ($keepRaw): array {
            if (! $keepRaw) {
                unset($parsed['raw_start'], $parsed['raw_end']);
            }
            usort($parsed['items'], fn (array $a, array $b): int => [$a['dimension'], $a['month_slot'], $a['item_code']] <=> [$b['dimension'], $b['month_slot'], $b['item_code']]);

            return $parsed;
        };
        expect($sorted($fromPayload->toArray()))->toEqual($sorted($record->parsed));
    }
})->with(BrokenSubmissions::hardNames());

it('reads the raw payload, not the snapshot, when it reprocesses', function (): void {
    $record = quarantineThrough(ParsedSubmissionBuilder::new()->forFacility($this->facility)->withLga(null)->build());
    // A snapshot that disagrees with the payload: the payload wins.
    DB::table('quarantined_records')->where('id', $record->id)->update(['parsed' => json_encode([...$record->parsed, 'facility_ref' => 'NOT-A-FACILITY'])]);

    $outcome = app(CorrectAndReprocess::class)->execute($record->refresh(), ['lga_ref' => $this->facility->lga->code], $this->admin, GOOD_REASON);

    expect($outcome->status)->toBe(SubmissionStatus::Accepted)
        ->and(Assessment::query()->sole()->facility_id)->toBe($this->facility->id);
});
