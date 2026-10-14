<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Actions\BulkResolveQuarantine;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->lga = Lga::factory()->create(['code' => 'CHK', 'name' => 'Chikun']);
    $ward = Ward::factory()->create(['lga_id' => $this->lga->id]);
    // Three null-LGA submissions from Chikun: the same failure, the same fix.
    $this->records = collect(range(1, 3))->map(fn (): QuarantinedRecord => quarantineThrough(
        ParsedSubmissionBuilder::new()->forFacility(Facility::factory()->inWard($ward)->create())->withLga(null)->build(),
    ));
});

it('corrects and reprocesses every record failing the same rule, in one go', function (): void {
    $outcomes = app(BulkResolveQuarantine::class)->execute($this->records->pluck('id')->all(), 'correct', ['lga_ref' => 'CHK'], $this->admin, 'Whole Chikun batch left the LGA blank');

    expect($outcomes)->toHaveCount(3)
        ->and(Assessment::query()->count())->toBe(3)
        ->and(QuarantinedRecord::query()->where('resolution', QuarantineResolution::Reprocessed)->count())->toBe(3);
});

it('rejects every record failing the same rule', function (): void {
    app(BulkResolveQuarantine::class)->execute($this->records->pluck('id')->all(), 'reject', [], $this->admin, 'Training submissions from the workshop');

    expect(QuarantinedRecord::query()->where('resolution', QuarantineResolution::Rejected)->count())->toBe(3);
});

it('writes one audit entry per record, each with the reason', function (): void {
    app(BulkResolveQuarantine::class)->execute($this->records->pluck('id')->all(), 'reject', [], $this->admin, 'Training submissions from the workshop');

    foreach ($this->records as $record) {
        $entry = Activity::query()->forSubject($record)->where('event', '!=', 'created')->sole();
        expect($entry->event)->toBe('rejected')
            ->and($entry->properties['reason'])->toBe('Training submissions from the workshop');
    }
});

it('refuses records that fail different rules, changing nothing', function (): void {
    $other = quarantineThrough(ParsedSubmissionBuilder::new()->withWard('Quarterly')->build());
    $ids = [...$this->records->pluck('id')->all(), $other->id];

    expect(fn () => app(BulkResolveQuarantine::class)->execute($ids, 'reject', [], $this->admin, 'Training submissions from the workshop'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.mixed_rules'));
    expect(QuarantinedRecord::query()->where('status', QuarantineStatus::Open)->count())->toBe(4)
        ->and(Activity::query()->where('subject_type', (new QuarantinedRecord)->getMorphClass())->whereIn('event', ['rejected', 'corrected'])->count())->toBe(0);
});

it('refuses without a reason of at least 10 characters', function (): void {
    expect(fn () => app(BulkResolveQuarantine::class)->execute($this->records->pluck('id')->all(), 'reject', [], $this->admin, 'bulk'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.reason_too_short'));
});

it('is atomic: one record that cannot be resolved rolls back the whole batch', function (): void {
    $this->records[2]->update(['status' => QuarantineStatus::Resolved, 'resolution' => QuarantineResolution::Rejected]);

    expect(fn () => app(BulkResolveQuarantine::class)->execute($this->records->pluck('id')->all(), 'correct', ['lga_ref' => 'CHK'], $this->admin, 'Whole Chikun batch left the LGA blank'))
        ->toThrow(QuarantineActionRefused::class);
    expect(Assessment::query()->count())->toBe(0)
        ->and(QuarantinedRecord::query()->where('resolution', QuarantineResolution::Reprocessed)->count())->toBe(0);
});

it('refuses an unknown action, an empty list and an unknown id', function (array $ids, string $action): void {
    $ids = $ids === ['all'] ? $this->records->pluck('id')->all() : $ids;

    expect(fn () => app(BulkResolveQuarantine::class)->execute($ids, $action, [], $this->admin, 'Training submissions from the workshop'))
        ->toThrow(QuarantineActionRefused::class);
})->with([
    'override is per assessment' => [['all'], 'override'],
    'empty' => [[], 'reject'],
    'unknown id' => [[999_999], 'reject'],
]);
