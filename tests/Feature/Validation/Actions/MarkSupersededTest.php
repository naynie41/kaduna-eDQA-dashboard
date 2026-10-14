<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\MarkSuperseded;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Listeners\RescoreAfterQuarantineResolved;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->record = quarantineThrough(ParsedSubmissionBuilder::new()->withLga(null)->build());
});

it('resolves an open record as superseded when an ODK edit of it arrives', function (): void {
    Event::fake([QuarantineResolved::class]);
    $edit = Submission::factory()->create(['deprecated_id' => $this->record->instance_id]);

    $resolved = app(MarkSuperseded::class)->execute($edit);

    $record = $this->record->refresh();
    expect($resolved)->toHaveCount(1)
        ->and($record->status)->toBe(QuarantineStatus::Resolved)
        ->and($record->resolution)->toBe(QuarantineResolution::Superseded)
        ->and($record->resolved_by)->toBeNull()
        ->and($record->resolution_note)->toBe(__('quarantine.superseded_note', ['instance' => $edit->instance_id]))
        ->and($record->submission->status)->toBe(SubmissionStatus::Superseded)
        ->and($record->submission->superseded_by_id)->toBe($edit->id);

    Event::assertDispatched(QuarantineResolved::class, fn (QuarantineResolved $e): bool => $e->resolution === QuarantineResolution::Superseded);
});

it('audits the automatic resolution, with no person as the actor', function (): void {
    app(MarkSuperseded::class)->execute(Submission::factory()->create(['deprecated_id' => $this->record->instance_id]));

    $entry = Activity::query()->forSubject($this->record)->where('event', '!=', 'created')->sole();
    expect($entry->event)->toBe('superseded')
        ->and($entry->causer)->toBeNull()
        ->and($entry->properties['attributes'])->toMatchArray(['status' => 'resolved', 'resolution' => 'superseded']);
});

it('does nothing for a submission that edits nothing, or a record already resolved', function (): void {
    expect(app(MarkSuperseded::class)->execute(Submission::factory()->create()))->toBe([]);

    $this->record->update(['status' => QuarantineStatus::Resolved, 'resolution' => QuarantineResolution::Rejected]);
    expect(app(MarkSuperseded::class)->execute(Submission::factory()->create(['deprecated_id' => $this->record->instance_id])))->toBe([]);
});

it('hands a resolution to the rescore placeholder', function (): void {
    Event::fake([QuarantineResolved::class]);

    Event::assertListening(QuarantineResolved::class, RescoreAfterQuarantineResolved::class);
});
