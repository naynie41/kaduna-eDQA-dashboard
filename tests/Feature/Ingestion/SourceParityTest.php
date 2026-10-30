<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use Tests\Support\FixtureWorld;
use Tests\Support\Rows;

// Source parity (CONVENTION.md §8): every fixture's ODK Central and KoboToolbox raw rows, each
// through its own source's normaliser and then the same parser, give the same ParsedSubmission,
// apart from what each source names its own way (source, external_id and the form version label),
// and end with the same outcome through the real pipeline. Kobo-only cases (an oddity of its rows)
// are checked against their own parsed fixture.

/** The fields a source names its own way: compared apart. */
const SOURCE_OWN = ['source', 'external_id', 'form_version'];

beforeEach(function (): void {
    FixtureWorld::build();
    CurrentRuleVersion::forget();
});

/** @return array<string, array{0: string, 1: string}> "kind/name" => [kind, name] for the raw rows of one source */
function sourceFixtures(string $source): array
{
    $cases = [];
    foreach (glob(dirname(__DIR__, 2)."/Fixtures/sources/{$source}/*/*.json") ?: [] as $file) {
        $cases[basename(dirname($file)).'/'.basename($file, '.json')] = [basename(dirname($file)), basename($file, '.json')];
    }

    return $cases;
}

/** @return array<array-key, mixed> */
function parityJson(string $path): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/{$path}")), true, flags: JSON_THROW_ON_ERROR);
}

/** @param  array<string, mixed>  $parsed */
function withoutSourceOwn(array $parsed): array
{
    return array_diff_key($parsed, array_flip(SOURCE_OWN));
}

it('has a Kobo row for every ODK fixture, and a parsed fixture and outcome for every Kobo row', function (): void {
    $kobo = sourceFixtures('kobo');

    expect(array_diff_key(sourceFixtures('odk'), $kobo))->toBe([])
        ->and(count($kobo))->toBe(count(sourceFixtures('odk')) + 2);
    foreach ($kobo as [$kind, $name]) {
        expect(base_path("tests/Fixtures/parsed/{$kind}/{$name}.json"))->toBeFile()
            ->and(base_path("tests/Fixtures/parsed/{$kind}/{$name}.expected.json"))->toBeFile();
    }
});

it('parses the ODK and Kobo rows of one visit to the same submission, apart from what each source names its own way', function (string $kind, string $name): void {
    $odk = odkParse(parityJson("sources/odk/{$kind}/{$name}.json"))->toArray();
    $raw = parityJson("sources/kobo/{$kind}/{$name}.json");
    $kobo = koboParse($raw)->toArray();

    expect(withoutSourceOwn($kobo))->toBe(withoutSourceOwn($odk))
        ->and($odk)->toBe(parityJson("parsed/{$kind}/{$name}.json"))
        ->and($kobo['source'])->toBe('kobo')
        ->and($kobo['external_id'])->toBe((string) $raw['_id'])
        ->and($kobo['form_version'])->toBe($raw['__version__']);
})->with(sourceFixtures('odk'));

it('maps each mapped Kobo version to the same field map as the ODK version it pairs with', function (): void {
    expect(koboParse(parityJson('sources/kobo/valid/clean-visit.json'))->formVersionKnown)->toBeTrue()
        ->and(koboParse(parityJson('sources/kobo/valid/older-version-2025-2.json'))->formVersionKnown)->toBeTrue()
        ->and(koboParse(parityJson('sources/kobo/broken/unknown-form-version.json'))->formVersionKnown)->toBeFalse();
});

it('parses each Kobo-only row into exactly its parsed fixture', function (string $kind, string $name): void {
    expect(koboParse(parityJson("sources/kobo/{$kind}/{$name}.json"))->toArray())->toBe(parityJson("parsed/{$kind}/{$name}.json"));
})->with(fn (): array => array_filter(sourceFixtures('kobo'), fn (array $c): bool => str_starts_with($c[1], 'kobo-')));

it('ends every Kobo row with the outcome its fixture expects, through the real pipeline', function (string $kind, string $name): void {
    $raw = parityJson("sources/kobo/{$kind}/{$name}.json");
    ['status' => $status, 'codes' => $codes] = parityJson("parsed/{$kind}/{$name}.expected.json");
    $parsed = koboParse($raw);
    $submission = Submission::query()->create([
        'source_id' => Rows::sourceId('kobo'), 'external_id' => $parsed->externalId, 'instance_id' => $parsed->instanceId, 'form_ref' => 'aKdDqaForm2026',
        'form_version' => $parsed->formVersion, 'payload' => $raw, 'submitted_at' => $parsed->submittedAt, 'received_at' => now(), 'status' => SubmissionStatus::Received,
    ]);

    $outcome = app(ProcessParsedBatch::class)->execute([[$submission, $parsed]])[0];

    expect($outcome->status->value)->toBe($status);
    if ($status === 'quarantined') {
        $record = QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->sole();
        expect(array_column($record->failures, 'code'))->toBe($codes)
            ->and($record->source_id)->toBe(Rows::sourceId('kobo'));
    } else {
        expect(Assessment::query()->findOrFail($outcome->assessmentId)->flags)->toBe($codes);
    }
})->with(sourceFixtures('kobo'));

it('names the source in the unknown-version detail', function (): void {
    $parsed = koboParse(parityJson('sources/kobo/broken/unknown-form-version.json'));
    $submission = Submission::query()->create([
        'source_id' => Rows::sourceId('kobo'), 'external_id' => $parsed->externalId, 'instance_id' => $parsed->instanceId, 'form_ref' => 'aKdDqaForm2026',
        'form_version' => $parsed->formVersion, 'payload' => [], 'submitted_at' => $parsed->submittedAt, 'received_at' => now(), 'status' => SubmissionStatus::Received,
    ]);

    app(ProcessParsedBatch::class)->execute([[$submission, $parsed]]);

    expect(QuarantinedRecord::query()->sole()->failures[0]['detail'])->toBe('kobo form version vKdUnmapped20213 has no field map');
});
