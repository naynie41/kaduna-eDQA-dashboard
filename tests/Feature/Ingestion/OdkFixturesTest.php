<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// The golden ODK fixtures (tests/Fixtures/README.md). Every raw ODK entry, through ODK's
// normaliser and then the parser (odkParse, as the pipeline reads it), is exactly its parsed fixture, and, run through the parser and the real pipeline against FixtureWorld, ends
// with exactly the outcome Phase 2A expects of it.

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
});

/** @return array<string, array{0: string, 1: string}> "kind/name" => [kind, name] */
function odkFixtures(): array
{
    $cases = [];
    // __DIR__, not base_path(): datasets are built before the application boots.
    foreach (glob(dirname(__DIR__, 2).'/Fixtures/sources/odk/*/*.json') ?: [] as $file) {
        $kind = basename(dirname($file));
        $name = basename($file, '.json');
        $cases["{$kind}/{$name}"] = [$kind, $name];
    }

    return $cases;
}

/** @return array<array-key, mixed> */
function fixtureJson(string $path): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/{$path}")), true, flags: JSON_THROW_ON_ERROR);
}

it('has every case three times: raw, parsed and the expected outcome', function (): void {
    expect(odkFixtures())->toHaveCount(23);
    foreach (odkFixtures() as [$kind, $name]) {
        expect(base_path("tests/Fixtures/parsed/{$kind}/{$name}.json"))->toBeFile()
            ->and(base_path("tests/Fixtures/parsed/{$kind}/{$name}.expected.json"))->toBeFile();
    }
});

it('parses the raw ODK entry into exactly its parsed fixture', function (string $kind, string $name): void {
    $parsed = odkParse(fixtureJson("sources/odk/{$kind}/{$name}.json"));

    expect($parsed->toArray())->toBe(fixtureJson("parsed/{$kind}/{$name}.json"));
})->with(odkFixtures());

it('keeps every parsed fixture a valid ParsedSubmission', function (string $kind, string $name): void {
    $fixture = fixtureJson("parsed/{$kind}/{$name}.json");

    expect(ParsedSubmission::fromArray($fixture)->toArray())->toBe($fixture);
})->with(odkFixtures());

it('ends with the outcome 2A expects, through the parser and the real pipeline', function (string $kind, string $name): void {
    $raw = fixtureJson("sources/odk/{$kind}/{$name}.json");
    ['status' => $status, 'codes' => $codes] = fixtureJson("parsed/{$kind}/{$name}.expected.json");
    $parsed = odkParse($raw);
    $submission = Submission::query()->create([
        'source_id' => Rows::sourceId(), 'external_id' => $parsed->instanceId, 'instance_id' => $parsed->instanceId, 'form_ref' => 'dqa', 'form_version' => $parsed->formVersion,
        'payload' => $raw, 'submitted_at' => $parsed->submittedAt, 'received_at' => now(), 'status' => SubmissionStatus::Received,
    ]);

    $outcome = app(ProcessParsedBatch::class)->execute([[$submission, $parsed]])[0];

    expect($outcome->status->value)->toBe($status);
    if ($status === 'quarantined') {
        $record = QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->sole();
        expect(array_column($record->failures, 'code'))->toBe($codes)
            ->and($outcome->assessmentId)->toBeNull();
    } else {
        expect(Assessment::query()->findOrFail($outcome->assessmentId)->flags)->toBe($codes)
            ->and(QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->exists())->toBeFalse();
    }
})->with(odkFixtures());
