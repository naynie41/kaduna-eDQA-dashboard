<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\CorrectAndReprocess;
use App\Domain\Validation\Actions\RejectQuarantinedRecord;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Events\QuarantineResolved;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->record = quarantineThrough(ParsedSubmissionBuilder::new()->withFacilityRef('2019.00')->build());
});

it('rejects a record permanently, with the reason, and rejects its submission', function (): void {
    Event::fake([QuarantineResolved::class]);

    app(RejectQuarantinedRecord::class)->execute($this->record, $this->admin, 'Test visit, not a real assessment');

    $record = $this->record->refresh();
    expect($record->status)->toBe(QuarantineStatus::Resolved)
        ->and($record->resolution)->toBe(QuarantineResolution::Rejected)
        ->and($record->resolved_by)->toBe($this->admin->id)
        ->and($record->resolution_note)->toBe('Test visit, not a real assessment')
        ->and($record->submission->status)->toBe(SubmissionStatus::Rejected)
        ->and(Assessment::query()->count())->toBe(0);

    Event::assertDispatched(QuarantineResolved::class, fn (QuarantineResolved $e): bool => $e->resolution === QuarantineResolution::Rejected);
});

it('writes one audit entry with old and new status and the reason', function (): void {
    app(RejectQuarantinedRecord::class)->execute($this->record, $this->admin, 'Test visit, not a real assessment');

    $entry = Activity::query()->forSubject($this->record)->where('event', '!=', 'created')->sole();
    expect($entry->event)->toBe('rejected')
        ->and($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['reason'])->toBe('Test visit, not a real assessment')
        ->and($entry->properties['old'])->toMatchArray(['status' => 'open', 'resolution' => null])
        ->and($entry->properties['attributes'])->toMatchArray(['status' => 'resolved', 'resolution' => 'rejected']);
});

it('refuses without a reason of at least 10 characters', function (): void {
    expect(fn () => app(RejectQuarantinedRecord::class)->execute($this->record, $this->admin, 'no'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.reason_too_short'));
    expect($this->record->refresh()->status)->toBe(QuarantineStatus::Open);
});

it('is permanent: a rejected record cannot be rejected again or corrected', function (): void {
    app(RejectQuarantinedRecord::class)->execute($this->record, $this->admin, 'Test visit, not a real assessment');

    expect(fn () => app(RejectQuarantinedRecord::class)->execute($this->record->refresh(), $this->admin, 'Rejecting it a second time'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.already_resolved'))
        ->and(fn () => app(CorrectAndReprocess::class)->execute($this->record->refresh(), ['facility_ref' => 'X'], $this->admin, 'Trying to bring it back'))
        ->toThrow(QuarantineActionRefused::class);
});
