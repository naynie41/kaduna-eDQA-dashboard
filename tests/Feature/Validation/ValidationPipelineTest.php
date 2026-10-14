<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\DTOs\ValidationResult;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\ColumnDriftRule;
use App\Domain\Validation\Rules\UnknownFormVersionRule;
use App\Domain\Validation\ValidationContextFactory;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\Support\ParsedSubmissionBuilder;
use Tests\Support\ThrowingRule;

beforeEach(function (): void {
    ScoringRuleVersion::factory()->published()->create();
});

function validate(ParsedSubmission $submission): ValidationResult
{
    $context = app(ValidationContextFactory::class)->forBatch([$submission]);

    return app(ValidationPipeline::class)->validate($submission, $context);
}

/** @param  list<RuleFailure>  $failures */
function codes(array $failures): array
{
    return array_map(fn (RuleFailure $f): string => $f->code, $failures);
}

it('passes a valid submission with no failures', function (): void {
    $result = validate(ParsedSubmissionBuilder::new()->build());

    expect($result->passed())->toBeTrue()
        ->and($result->isFlagged())->toBeFalse()
        ->and($result->failures())->toBe([]);
});

it('collects every failure, in rule order, instead of stopping at the first', function (): void {
    $result = validate(ParsedSubmissionBuilder::new()
        ->withWard('Quarterly')->withLga(null)->withFacilityRef('2019.00')->withVisitMinutes(12)->build());

    expect($result->passed())->toBeFalse()
        ->and(codes($result->hardFailures))->toBe(['COLUMN_DRIFT', 'LGA_UNKNOWN', 'FACILITY_UNKNOWN'])
        ->and(codes($result->softFlags))->toBe(['VISIT_TOO_SHORT']);
});

it('passes a soft-only submission, flagged', function (): void {
    $result = validate(ParsedSubmissionBuilder::new()->withVisitMinutes(12)->withAllItems('pass')->build());

    expect($result->passed())->toBeTrue()
        ->and($result->isFlagged())->toBeTrue()
        ->and(codes($result->softFlags))->toBe(['ALL_PERFECT', 'VISIT_TOO_SHORT']);
});

it('skips every rule that reads mapped fields when the form version is unknown', function (): void {
    // Every one of these would fail too, but read through the wrong map they mean nothing.
    $result = validate(ParsedSubmissionBuilder::new()
        ->withFormVersion('2019.1')->withLga(null)->withWard('Quarterly')->withVisitMinutes(5)->build());

    expect(codes($result->failures()))->toBe(['UNKNOWN_FORM_VERSION']);
});

it('turns a rule that throws into a RULE_ERROR hard failure and keeps going', function (): void {
    config(['edqa.validation.rules' => [
        'BROKEN_RULE' => ThrowingRule::class,
        'COLUMN_DRIFT' => ColumnDriftRule::class,
    ]]);
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'validation.rule_error'
        && $context['rule'] === 'BROKEN_RULE'
        && $context['message'] === 'division by zero in test rule'
        && ! array_key_exists('payload', $context));
    Log::shouldReceive('channel')->with('edqa')->andReturn($logger);

    $result = validate(ParsedSubmissionBuilder::new()->withWard('Quarterly')->build());

    expect($result->passed())->toBeFalse()
        ->and(codes($result->hardFailures))->toBe(['RULE_ERROR', 'COLUMN_DRIFT'])
        ->and($result->hardFailures[0]->severity)->toBe(Severity::Hard)
        ->and($result->hardFailures[0]->detail)->toBe('rule BROKEN_RULE failed to run: division by zero in test rule');
});

it('treats the form-version gate throwing as failing it', function (): void {
    config(['edqa.validation.rules' => [
        'UNKNOWN_FORM_VERSION' => ThrowingRule::class,
        'COLUMN_DRIFT' => ColumnDriftRule::class,
    ]]);
    Log::shouldReceive('channel')->andReturn(Mockery::spy(LoggerInterface::class));

    $result = validate(ParsedSubmissionBuilder::new()->withWard('Quarterly')->build());

    expect(codes($result->failures()))->toBe(['RULE_ERROR']);
});

it('reports a rule class that cannot even be built as a RULE_ERROR', function (): void {
    config(['edqa.validation.rules' => [
        'UNKNOWN_FORM_VERSION' => UnknownFormVersionRule::class,
        'MISSING_RULE' => 'App\Domain\Validation\Rules\DoesNotExistRule',
    ]]);
    Log::shouldReceive('channel')->andReturn(Mockery::spy(LoggerInterface::class));

    expect(codes(validate(ParsedSubmissionBuilder::new()->build())->hardFailures))->toBe(['RULE_ERROR']);
});
