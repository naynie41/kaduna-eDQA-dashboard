<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Plan\Enums\PlanActionStatus;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Scoring\Enums\Band;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Enums\Severity;

// Values are stored in the database and mirrored by CHECK constraints: changing one needs a
// migration, so the exact list is pinned here.
dataset('enums', [
    'Dimension' => [Dimension::class, ['availability', 'consistency', 'validity']],
    'Band' => [Band::class, ['strong', 'acceptable', 'review', 'needs_action']],
    'Severity' => [Severity::class, ['hard', 'soft']],
    'RoundStatus' => [RoundStatus::class, ['open', 'closed']],
    'SubmissionStatus' => [SubmissionStatus::class, [
        'received', 'parsed', 'validated', 'accepted', 'flagged', 'quarantined', 'rejected', 'superseded',
    ]],
    'QuarantineStatus' => [QuarantineStatus::class, ['open', 'resolved']],
    'QuarantineResolution' => [QuarantineResolution::class, ['reprocessed', 'rejected', 'superseded']],
    'Ownership' => [Ownership::class, ['public', 'private']],
    'FacilityLevel' => [FacilityLevel::class, ['primary', 'secondary', 'tertiary']],
    'PullTrigger' => [PullTrigger::class, ['scheduled', 'manual', 'webhook']],
    'PlanActionStatus' => [PlanActionStatus::class, ['not_started', 'in_progress', 'done']],
]);

it('has exactly the documented values', function (string $enum, array $values): void {
    expect(array_map(fn (BackedEnum $case): string|int => $case->value, $enum::cases()))->toBe($values);
})->with('enums');

it('labels every case from lang/en/enums.php', function (string $enum): void {
    foreach ($enum::cases() as $case) {
        expect($case->label())
            ->toBeString()
            ->not->toBeEmpty()
            ->not->toStartWith('enums.');
    }
})->with('enums');

it('labels bands as users see them', function (): void {
    expect(Band::NeedsAction->label())->toBe('Needs action')
        ->and(PlanActionStatus::NotStarted->label())->toBe('Not started');
});
