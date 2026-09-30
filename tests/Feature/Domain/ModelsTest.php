<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Exceptions\SubmissionPayloadIsImmutable;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Support\Facades\DB;

// ---- Raw ODK payloads are never mutated (CLAUDE.md hard rule 8, SECURITY.md §5)

it('refuses to change a submission payload after creation', function (): void {
    $submission = Submission::factory()->create();

    expect(fn () => $submission->update(['payload' => ['tampered' => true]]))
        ->toThrow(SubmissionPayloadIsImmutable::class);

    expect(DB::table('submissions')->where('id', $submission->id)->value('payload'))
        ->not->toContain('tampered');
});

it('still lets a submission move through its lifecycle', function (): void {
    $submission = Submission::factory()->create();

    $submission->update(['status' => SubmissionStatus::Accepted, 'processed_at' => now()]);

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Accepted);
});

it('does not count re-assigning the same payload as a change', function (): void {
    $submission = Submission::factory()->create();

    $submission->payload = $submission->payload;
    $submission->status = SubmissionStatus::Parsed;
    $submission->save();

    expect($submission->refresh()->status)->toBe(SubmissionStatus::Parsed);
});

// ---- Casts

it('casts enums, dates and JSON', function (): void {
    $facility = Facility::factory()->create();
    $round = Round::factory()->create();
    $score = AssessmentScore::factory()->create();

    expect($facility->level)->toBeInstanceOf(FacilityLevel::class)
        ->and($facility->ownership)->toBeInstanceOf(Ownership::class)
        ->and($facility->is_active)->toBeTrue()
        ->and($round->status)->toBe(RoundStatus::Open)
        ->and($round->window_start)->toBeInstanceOf(CarbonImmutable::class)
        ->and($round->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($score->dimension)->toBeInstanceOf(Dimension::class)
        ->and(Submission::factory()->create()->payload)->toBeArray();
});

// ---- Model::shouldBeStrict() outside production

it('is strict about attributes outside production', function (): void {
    $facility = Facility::query()->select('id')->find(Facility::factory()->create()->id);

    expect(fn () => $facility->name)->toThrow(MissingAttributeException::class);
});
