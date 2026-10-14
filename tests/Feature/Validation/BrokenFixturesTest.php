<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Tests\Support\BrokenSubmissions;
use Tests\Support\ParsedSubmissionBuilder;

// CLAUDE.md §6 step 6 acceptance: each fixture quarantines with the right code, end to end
// through ProcessParsedBatch, and with exactly that code: no extra failure, no missing one.

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
});

it('quarantines each broken fixture with exactly its expected codes', function (string $name): void {
    [$parsed, $expected] = BrokenSubmissions::build($name);

    $outcome = app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]])[0];

    $record = QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->sole();
    expect($outcome->status)->toBe(SubmissionStatus::Quarantined)
        ->and(array_column($record->failures, 'code'))->toBe($expected)
        ->and(Assessment::query()->whereHas('submission', fn ($q) => $q->where('instance_id', $parsed->instanceId))->exists())->toBeFalse();
})->with(BrokenSubmissions::hardNames());

it('accepts each soft fixture, flagged with exactly its expected codes', function (string $name): void {
    [$parsed, $expected] = BrokenSubmissions::build($name);

    $outcome = app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]])[0];

    expect($outcome->status)->toBe(SubmissionStatus::Flagged)
        ->and(Assessment::query()->findOrFail($outcome->assessmentId)->flags)->toBe($expected)
        ->and(QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->exists())->toBeFalse();
})->with(BrokenSubmissions::softNames());

it('accepts the unbroken submission with no flags', function (): void {
    $parsed = ParsedSubmissionBuilder::new()->build();

    $outcome = app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]])[0];

    expect($outcome->status)->toBe(SubmissionStatus::Accepted)
        ->and(Assessment::query()->findOrFail($outcome->assessmentId)->flags)->toBe([]);
});

it('has a fixture for every one of the 14 rules', function (): void {
    expect(BrokenSubmissions::coveredCodes())->toEqualCanonicalizing(array_keys(config('edqa.validation.rules')));
});
