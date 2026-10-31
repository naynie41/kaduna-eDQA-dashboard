<?php

declare(strict_types=1);

use App\Domain\Ingestion\SourceLinks;

// Deep links to a submission on its source (SECURITY.md §6): the configured base URL and IDs that
// pass their pattern, never a payload string. Anything else is no link at all.

const LINK_INSTANCE = 'uuid:6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f3a4b';

beforeEach(function (): void {
    configureSources();
    $this->links = app(SourceLinks::class);
});

it('links an ODK submission to its page in Central', function (): void {
    expect($this->links->submission('odk', LINK_INSTANCE))
        ->toBe('https://central.example.org/#/projects/7/forms/dqa/submissions/uuid%3A6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f3a4b');
});

it('links a Kobo submission to its record on the server', function (): void {
    expect($this->links->submission('kobo', '48213'))
        ->toBe('https://kobo.example.org/api/v2/assets/aKdDqaForm2026/data/48213/');
});

it('keeps a path in the base URL and drops a trailing slash', function (): void {
    config(['edqa.sources.kobo.url' => 'https://Kobo.Example.org/kobo/']);

    expect($this->links->submission('kobo', '7'))->toBe('https://kobo.example.org/kobo/api/v2/assets/aKdDqaForm2026/data/7/');
});

it('gives no ODK link for an ID that is not an instance ID', function (?string $id): void {
    expect($this->links->submission('odk', $id))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'no prefix' => '6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f3a4b',
    'too short' => 'uuid:6f1c2a9e',
    'upper case' => 'uuid:6F1C2A9E-3B4D-4E5F-8A7B-9C0D1E2F3A4B',
    'path traversal' => 'uuid:../../../../admin/users/xxxxxxxxxxxxxxxxxxxxx',
    'a script' => 'uuid:"><script>alert(1)</script>xxxxxxxxxxxx',
    'a javascript URL' => 'javascript:alert(document.cookie)//xxxxxxxxxxxxx',
    'another host' => '//evil.example.com/6f1c2a9e-3b4d-4e5f-8a7b-9c',
    'a trailing newline' => LINK_INSTANCE."\n",
    'a query' => 'uuid:6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f3a4b?x=1',
    'a fragment' => 'uuid:6f1c2a9e-3b4d-4e5f-8a7b-9c0d1e2f#/x',
]);

it('gives no Kobo link for an _id that is not a plain number', function (string $id): void {
    expect($this->links->submission('kobo', $id))->toBeNull();
})->with([
    'empty' => '',
    'zero' => '0',
    'leading zero' => '012',
    'negative' => '-1',
    'decimal' => '1.5',
    'an instance ID' => LINK_INSTANCE,
    'path traversal' => '1/../../../users',
    'a query' => '1?format=json',
    'a script' => '1<script>',
    'too long' => str_repeat('9', 19),
    'a trailing newline' => "1\n",
]);

it('gives no link when the configured form or asset is not a plain ID', function (string $config, string $value, string $source, string $id): void {
    config([$config => $value]);

    expect($this->links->submission($source, $id))->toBeNull();
})->with([
    'ODK project not a number' => ['edqa.sources.odk.project_id', '7/../8', 'odk', LINK_INSTANCE],
    'ODK form with a slash' => ['edqa.sources.odk.form_id', 'dqa/../admin', 'odk', LINK_INSTANCE],
    'ODK form with a hash' => ['edqa.sources.odk.form_id', 'dqa#x', 'odk', LINK_INSTANCE],
    'Kobo asset with a slash' => ['edqa.sources.kobo.asset_uid', 'aKd/../x', 'kobo', '1'],
    'Kobo asset with a dash' => ['edqa.sources.kobo.asset_uid', 'aKd-x', 'kobo', '1'],
]);

it('gives no link while the base URL is missing, not https, or not a plain URL', function (?string $url): void {
    config(['edqa.sources.odk.url' => $url, 'edqa.sources.kobo.url' => $url]);

    expect($this->links->submission('odk', LINK_INSTANCE))->toBeNull()
        ->and($this->links->submission('kobo', '1'))->toBeNull();
})->with([
    'unset' => null,
    'plain http' => 'http://central.example.org',
    'javascript' => 'javascript:alert(1)',
    'no scheme' => 'central.example.org',
    'with a user' => 'https://user:pass@central.example.org',
    'with a query' => 'https://central.example.org/?x=1',
    'with a fragment' => 'https://central.example.org/#/x',
    'a strange path' => 'https://central.example.org/a b',
]);

it('gives no link for a source not in config', function (): void {
    expect($this->links->submission('dhis2', '1'))->toBeNull();
});
