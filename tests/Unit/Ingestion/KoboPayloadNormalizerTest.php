<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\EnvelopeAttachment;
use App\Domain\Ingestion\Kobo\KoboPayloadNormalizer;

// A KPI v2 data row → the common SubmissionEnvelope (ARCHITECTURE.md §5.2): Kobo's own fields
// into meta, its flat slash keys nested into groups as ODK nests them, nothing guessed.

function koboCleanVisit(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/sources/kobo/valid/clean-visit.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('reads Kobo\'s own fields into meta, its zoneless submission time as UTC', function (): void {
    $meta = (new KoboPayloadNormalizer)->normalise(koboCleanVisit())->meta;

    expect($meta->source)->toBe('kobo')
        ->and($meta->externalId)->toBe('1001')
        ->and($meta->instanceId)->toBe('uuid:fx-clean-visit')
        ->and($meta->deprecatedId)->toBeNull()
        ->and($meta->submittedAt?->toIso8601String())->toBe('2026-04-14T08:50:00+00:00')
        ->and($meta->updatedAt)->toBeNull()
        ->and($meta->formVersion)->toBe('vKd26Q1aBcDeFgHiJkLmNo')
        ->and($meta->submitter)->toBe('hauwa.ibrahim')
        ->and($meta->deviceId)->toBe('collect:hauwa01')
        ->and($meta->attachments)->toBe([]);
});

it('reads a submission time that carries its zone as written', function (): void {
    $meta = (new KoboPayloadNormalizer)->normalise(['_submission_time' => '2026-04-14T09:50:00+01:00'])->meta;

    expect($meta->submittedAt?->utc()->toIso8601String())->toBe('2026-04-14T08:50:00+00:00');
});

it('nests the slash keys into groups and leaves Kobo\'s own fields out of the answers', function (): void {
    $data = (new KoboPayloadNormalizer)->normalise(koboCleanVisit())->data;

    expect($data['facility'])->toBe(['lga' => 'CHK', 'ward' => 'CHK-KUJ', 'facility_code' => 'FX-0001'])
        ->and($data['availability']['avail_m1_nhmis_summary'])->toBe('yes')
        ->and($data['start'])->toBe('2026-04-14T09:00:00.000+01:00')
        ->and($data)->not->toHaveKeys(['_id', '_uuid', '_status', '_geolocation', '_tags', '_notes', '_validation_status', '_submission_time', '_submitted_by', '_attachments', '_xform_id_string', '__version__', 'meta', 'meta/instanceID', 'formhub', 'formhub/uuid', 'deviceid']);
});

it('reads an edit: a new instance ID, the version it replaces, the logical ID kept', function (): void {
    $raw = koboCleanVisit();
    $raw['meta/instanceID'] = 'uuid:fx-clean-visit-v2';
    $raw['meta/deprecatedID'] = 'uuid:fx-clean-visit';

    $meta = (new KoboPayloadNormalizer)->normalise($raw)->meta;

    expect($meta->externalId)->toBe('1001')
        ->and($meta->instanceId)->toBe('uuid:fx-clean-visit-v2')
        ->and($meta->deprecatedId)->toBe('uuid:fx-clean-visit');
});

it('reads a nested meta group, and falls back to _uuid when there is no meta at all', function (): void {
    $nested = (new KoboPayloadNormalizer)->normalise(['meta' => ['instanceID' => 'uuid:a', 'deprecatedID' => 'uuid:b']])->meta;
    $bare = (new KoboPayloadNormalizer)->normalise(['_uuid' => 'c'])->meta;
    $prefixed = (new KoboPayloadNormalizer)->normalise(['_uuid' => 'uuid:d'])->meta;

    expect([$nested->instanceId, $nested->deprecatedId])->toBe(['uuid:a', 'uuid:b'])
        ->and($bare->instanceId)->toBe('uuid:c')
        ->and($prefixed->instanceId)->toBe('uuid:d');
});

it('unflattens each row of a repeat to the repeat\'s own path', function (): void {
    $data = (new KoboPayloadNormalizer)->normalise([
        'stock/items' => [
            ['stock/items/name' => 'ORS', 'stock/items/count' => '4'],
            ['stock/items/name' => 'Zinc', 'stock/items/batch/code' => 'Z1'],
        ],
    ])->data;

    expect($data['stock']['items'])->toBe([
        ['name' => 'ORS', 'count' => '4'],
        ['name' => 'Zinc', 'batch' => ['code' => 'Z1']],
    ]);
});

it('keeps a key it cannot nest flat, rather than losing it', function (): void {
    $data = (new KoboPayloadNormalizer)->normalise([
        'facility' => 'an answer',
        'facility/lga' => 'CHK',      // a group where an answer already is
        'group/a' => '1',
        'group' => 'clash',           // an answer where a group already is
        'a//b' => 'x', 'a/' => 'y', '/a' => 'z',
    ])->data;

    expect($data)->toBe([
        'facility' => 'an answer',
        'facility/lga' => 'CHK',
        'group' => ['a' => '1'],
        'a//b' => 'x', 'a/' => 'y', '/a' => 'z',
    ]);
});

it('reads attachments, skipping deleted and nameless ones', function (): void {
    $meta = (new KoboPayloadNormalizer)->normalise(['_attachments' => [
        ['media_file_basename' => 'sign.jpg', 'mimetype' => 'image/jpeg', 'download_url' => 'https://kf.example.org/a/1', 'is_deleted' => false],
        ['filename' => 'user/attachments/x/photo.png', 'mimetype' => 'image/png'],
        ['media_file_basename' => 'gone.jpg', 'is_deleted' => true],
        ['mimetype' => 'image/png'],
        'not an attachment',
    ]])->meta;

    expect($meta->attachments)->toEqual([
        new EnvelopeAttachment('sign.jpg', 'image/jpeg', 'https://kf.example.org/a/1'),
        new EnvelopeAttachment('photo.png', 'image/png', null),
    ]);
});

it('leaves what it cannot read empty or null, never guessed', function (): void {
    $meta = (new KoboPayloadNormalizer)->normalise([
        '_id' => ['x'], '_submission_time' => 'FEBRUARY', '__version__' => null, '_submitted_by' => '', 'deviceid' => [], '_attachments' => 'none',
    ])->meta;

    expect($meta->externalId)->toBeNull()
        ->and($meta->instanceId)->toBe('')
        ->and($meta->submittedAt)->toBeNull()
        ->and($meta->formVersion)->toBe('')
        ->and($meta->submitter)->toBeNull()
        ->and($meta->deviceId)->toBeNull()
        ->and($meta->attachments)->toBe([]);
});

it('is deterministic and never changes the payload it reads', function (): void {
    $raw = koboCleanVisit();
    $copy = $raw;
    $normaliser = new KoboPayloadNormalizer;

    expect($normaliser->normalise($raw))->toEqual($normaliser->normalise($raw))
        ->and($raw)->toBe($copy);
});
