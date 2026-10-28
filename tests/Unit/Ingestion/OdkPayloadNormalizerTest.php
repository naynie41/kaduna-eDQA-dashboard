<?php

declare(strict_types=1);

use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;

// ODK Central's entry → the common SubmissionEnvelope (ARCHITECTURE.md §5.2): Central's own parts
// into meta, the answers as they are, nothing guessed.

function cleanVisit(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/sources/odk/valid/clean-visit.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('reads Central\'s own fields into meta', function (): void {
    $meta = (new OdkPayloadNormalizer)->normalise(cleanVisit())->meta;

    expect($meta->source)->toBe('odk')
        ->and($meta->externalId)->toBe('uuid:fx-clean-visit')
        ->and($meta->instanceId)->toBe('uuid:fx-clean-visit')
        ->and($meta->deprecatedId)->toBeNull()
        ->and($meta->submittedAt?->toIso8601String())->toBe('2026-04-14T08:50:00+00:00')
        ->and($meta->updatedAt)->toBeNull()
        ->and($meta->formVersion)->toBe('2026.1')
        ->and($meta->submitter)->toBe('hauwa.ibrahim')
        ->and($meta->deviceId)->toBe('collect:hauwa01')
        ->and($meta->attachments)->toBe([]);
});

it('keeps the answers exactly as sent, without Central\'s own parts', function (): void {
    $raw = cleanVisit();
    $data = (new OdkPayloadNormalizer)->normalise($raw)->data;

    expect($data)->not->toHaveKeys(['__id', '__system', 'meta'])
        ->and($data['facility'])->toBe($raw['facility'])
        ->and($data['availability'])->toBe($raw['availability'])
        ->and($data['start'])->toBe($raw['start']);
});

it('reads an edit: a new instance ID, the version it replaces, the logical ID kept, the edit time', function (): void {
    $raw = cleanVisit();
    $raw['meta'] = ['instanceID' => 'uuid:fx-clean-visit-v2', 'deprecatedID' => 'uuid:fx-clean-visit'];
    $raw['__system']['updatedAt'] = '2026-04-20T08:00:00.000Z';

    $meta = (new OdkPayloadNormalizer)->normalise($raw)->meta;

    expect($meta->externalId)->toBe('uuid:fx-clean-visit')
        ->and($meta->instanceId)->toBe('uuid:fx-clean-visit-v2')
        ->and($meta->deprecatedId)->toBe('uuid:fx-clean-visit')
        ->and($meta->updatedAt?->toIso8601String())->toBe('2026-04-20T08:00:00+00:00');
});

it('leaves what it cannot read empty or null, never guessed', function (): void {
    $raw = ['__id' => 'uuid:x', '__system' => ['submissionDate' => 'FEBRUARY', 'updatedAt' => '2026-04-20', 'formVersion' => null], 'meta' => []];

    $meta = (new OdkPayloadNormalizer)->normalise($raw)->meta;

    expect($meta->instanceId)->toBe('uuid:x') // no meta/instanceID: __id
        ->and($meta->submittedAt)->toBeNull()
        ->and($meta->updatedAt)->toBeNull() // a date with no time and zone is not ISO 8601
        ->and($meta->formVersion)->toBe('')
        ->and((new OdkPayloadNormalizer)->normalise([])->meta->instanceId)->toBe('');
});

it('is deterministic: the same payload, the same envelope', function (): void {
    $normaliser = new OdkPayloadNormalizer;

    expect($normaliser->normalise(cleanVisit()))->toEqual($normaliser->normalise(cleanVisit()));
});
