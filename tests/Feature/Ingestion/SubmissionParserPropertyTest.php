<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Odk\SubmissionParser;
use App\Domain\Scoring\CurrentRuleVersion;
use Database\Seeders\ScoringRuleVersionSeeder;

// The parser never throws on bad data (ARCHITECTURE.md §5.5): 500 payloads mangled at random
// (keys dropped, values of the wrong type, nulls, whole groups replaced, junk added) all parse,
// and the result serialises. Seeded, so a failure reproduces.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    CurrentRuleVersion::forget();
});

/** Every path to a key in the payload, as lists of keys. */
function payloadPaths(array $value, array $prefix = []): array
{
    $paths = [];
    foreach ($value as $key => $child) {
        $paths[] = [...$prefix, $key];
        if (is_array($child)) {
            $paths = [...$paths, ...payloadPaths($child, [...$prefix, $key])];
        }
    }

    return $paths;
}

function junk(): mixed
{
    return match (mt_rand(0, 13)) {
        0 => null,
        1 => mt_rand(-1_000_000, 1_000_000),
        2 => mt_rand() / mt_getrandmax() * 1000 - 500,
        3 => (bool) mt_rand(0, 1),
        4 => '',
        5 => str_repeat('x', mt_rand(1, 5000)),
        6 => 'FEBRUARY',
        7 => ['yes', 'no'],
        8 => ['nested' => ['deeper' => mt_rand()]],
        9 => '2026-02-30T25:61:00Z',
        10 => "\u{1F600} ünïcödé",
        11 => '347.66',
        12 => PHP_INT_MAX,
        default => [],
    };
}

/** Applies one random mangle to the payload at a random path. */
function mangle(array $payload): array
{
    $paths = payloadPaths($payload);
    if ($paths === []) {
        return ['junk' => junk()];
    }
    $path = $paths[mt_rand(0, count($paths) - 1)];
    $last = array_pop($path);
    $node = &$payload;
    foreach ($path as $key) {
        $node = &$node[$key];
    }
    match (mt_rand(0, 3)) {
        0 => $node = array_diff_key($node, [$last => true]),           // drop the key
        1 => $node[$last] = junk(),                                     // wrong type or value
        2 => $node[$last] = null,                                       // null
        default => $node['extra_'.mt_rand()] = junk(),                  // junk beside it
    };
    unset($node);

    return $payload;
}

it('never throws on 500 randomly mangled payloads', function (): void {
    mt_srand(20261017);
    $clean = json_decode((string) file_get_contents(base_path('tests/Fixtures/odk/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $parser = app(SubmissionParser::class);

    for ($i = 0; $i < 500; $i++) {
        $payload = $clean;
        // Sometimes a whole part goes: the system block, meta, a group, or everything.
        if (mt_rand(0, 9) === 0) {
            $payload = match (mt_rand(0, 3)) {
                0 => [...$payload, '__system' => junk()],
                1 => [...$payload, 'meta' => junk()],
                2 => [...$payload, 'availability' => junk()],
                default => [],
            };
        }
        foreach (range(1, mt_rand(1, 6)) as $_) {
            $payload = mangle($payload);
        }

        $parsed = $parser->parse($payload);

        expect($parsed)->toBeInstanceOf(ParsedSubmission::class)
            ->and(ParsedSubmission::fromArray($parsed->toArray())->toArray())->toBe($parsed->toArray());
    }
});
