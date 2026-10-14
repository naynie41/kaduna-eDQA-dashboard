<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Validation\Actions\OverrideSoftFlag;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->assessment = Assessment::factory()->create([
        'status' => 'flagged',
        'flags' => ['VISIT_TOO_SHORT', 'ASSESSOR_VOLUME', 'SLOT_ALL_NA:validity:3'],
    ]);
});

it('removes one flag and keeps the assessment flagged while others remain', function (): void {
    app(OverrideSoftFlag::class)->execute($this->assessment, 'VISIT_TOO_SHORT', $this->admin, 'Visit time confirmed with the supervisor');

    expect($this->assessment->refresh()->flags)->toBe(['ASSESSOR_VOLUME', 'SLOT_ALL_NA:validity:3'])
        ->and($this->assessment->status)->toBe('flagged');
});

it('marks the assessment accepted once its last soft flag is overridden; N/A notes stay', function (): void {
    app(OverrideSoftFlag::class)->execute($this->assessment, 'VISIT_TOO_SHORT', $this->admin, 'Visit time confirmed with the supervisor');
    app(OverrideSoftFlag::class)->execute($this->assessment->refresh(), 'ASSESSOR_VOLUME', $this->admin, 'Two assessors shared one phone that day');

    expect($this->assessment->refresh()->flags)->toBe(['SLOT_ALL_NA:validity:3'])
        ->and($this->assessment->status)->toBe('accepted');
});

it('records the override: old and new flags, the code and the reason', function (): void {
    app(OverrideSoftFlag::class)->execute($this->assessment, 'VISIT_TOO_SHORT', $this->admin, 'Visit time confirmed with the supervisor');

    $entry = Activity::query()->forSubject($this->assessment)->where('event', '!=', 'created')->sole();
    expect($entry->event)->toBe('flag_overridden')
        ->and($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['code'])->toBe('VISIT_TOO_SHORT')
        ->and($entry->properties['reason'])->toBe('Visit time confirmed with the supervisor')
        ->and($entry->properties['old']['flags'])->toBe(['VISIT_TOO_SHORT', 'ASSESSOR_VOLUME', 'SLOT_ALL_NA:validity:3'])
        ->and($entry->properties['attributes']['flags'])->toBe(['ASSESSOR_VOLUME', 'SLOT_ALL_NA:validity:3']);
});

it('refuses without a reason of at least 10 characters', function (): void {
    expect(fn () => app(OverrideSoftFlag::class)->execute($this->assessment, 'VISIT_TOO_SHORT', $this->admin, 'ok'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.reason_too_short'));
    expect($this->assessment->refresh()->flags)->toContain('VISIT_TOO_SHORT');
});

it('refuses a flag the assessment does not have', function (): void {
    expect(fn () => app(OverrideSoftFlag::class)->execute($this->assessment, 'ALL_PERFECT', $this->admin, 'Not actually flagged for this'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.flag_not_found', ['code' => 'ALL_PERFECT']));
});

it('refuses anything that is not a soft rule flag', function (string $code): void {
    expect(fn () => app(OverrideSoftFlag::class)->execute($this->assessment, $code, $this->admin, 'Trying to clear a note'))
        ->toThrow(QuarantineActionRefused::class, __('quarantine.errors.not_a_soft_flag', ['code' => $code]));
})->with(['SLOT_ALL_NA:validity:3', 'LGA_UNKNOWN']);
