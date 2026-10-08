<?php

declare(strict_types=1);

use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\DTOs\ValidationResult;
use App\Domain\Validation\Enums\Severity;

$hard = new RuleFailure('LGA_UNKNOWN', Severity::Hard, 'lga was blank', 'lga');
$soft = new RuleFailure('VISIT_TOO_SHORT', Severity::Soft, 'visit lasted 12 minutes');

it('passes and is unflagged with no failures', function (): void {
    $result = ValidationResult::fromFailures([]);

    expect($result->passed())->toBeTrue()
        ->and($result->isFlagged())->toBeFalse();
});

it('passes but is flagged with only soft failures', function () use ($soft): void {
    $result = ValidationResult::fromFailures([$soft]);

    expect($result->passed())->toBeTrue()
        ->and($result->isFlagged())->toBeTrue()
        ->and($result->softFlags)->toBe([$soft]);
});

it('fails on any hard failure and keeps every failure, in order', function () use ($hard, $soft): void {
    $second = new RuleFailure('FACILITY_UNKNOWN', Severity::Hard, "facility '2019.00' is not on the master list", 'facility_code');
    $result = ValidationResult::fromFailures([$hard, $soft, $second]);

    expect($result->passed())->toBeFalse()
        ->and($result->hardFailures)->toBe([$hard, $second])
        ->and($result->softFlags)->toBe([$soft])
        ->and($result->failures())->toBe([$hard, $second, $soft]);
});

it('refuses a failure filed under the wrong severity', function () use ($hard, $soft): void {
    expect(fn () => new ValidationResult([$soft], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ValidationResult([], [$hard]))->toThrow(InvalidArgumentException::class);
});

it('round-trips a failure through the array stored in quarantined_records.failures', function () use ($hard, $soft): void {
    expect(RuleFailure::fromArray($hard->toArray()))->toEqual($hard)
        ->and(RuleFailure::fromArray($soft->toArray()))->toEqual($soft)
        ->and($hard->toArray())->toBe(['code' => 'LGA_UNKNOWN', 'severity' => 'hard', 'detail' => 'lga was blank', 'field' => 'lga']);
});
