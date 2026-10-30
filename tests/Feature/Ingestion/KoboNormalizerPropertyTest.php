<?php

declare(strict_types=1);

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\CurrentRuleVersion;
use Database\Seeders\ScoringRuleVersionSeeder;

// Kobo's normaliser and the parser never throw on bad data (ARCHITECTURE.md §5.2, §5.5): 500 KPI
// data rows mangled at random (keys dropped, renamed into clashing or malformed paths, values of
// the wrong type, Kobo's own fields replaced, junk added) all normalise and parse, and the result
// serialises. Seeded, so a failure reproduces.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    CurrentRuleVersion::forget();
});

function koboJunk(): mixed
{
    return match (mt_rand(0, 15)) {
        0 => null,
        1 => mt_rand(-1_000_000, 1_000_000),
        2 => mt_rand() / mt_getrandmax() * 1000 - 500,
        3 => (bool) mt_rand(0, 1),
        4 => '',
        5 => str_repeat('x', mt_rand(1, 5000)),
        6 => 'yes no',
        7 => ['yes', 'no'],
        8 => ['nested' => ['deeper' => mt_rand()]],
        9 => '2026-02-30T25:61:00',
        10 => "\u{1F600} ünïcödé",
        11 => '347.66',
        12 => [['a/b' => 'row'], 'not a row', ['x' => ['y']]],
        13 => '91 181 0 -4',
        14 => PHP_INT_MAX,
        default => [],
    };
}

/** A key that is not quite a path: clashing, malformed, or Kobo's own. */
function koboJunkKey(): string
{
    return match (mt_rand(0, 7)) {
        0 => 'facility',
        1 => 'facility/lga/deeper',
        2 => 'a//b',
        3 => '/'.mt_rand(),
        4 => 'availability/',
        5 => '_'.mt_rand(),
        6 => 'meta/'.mt_rand(),
        default => 'extra_'.mt_rand(),
    };
}

/** Applies one random mangle to one key of the flat row. */
function koboMangle(array $row): array
{
    if ($row === []) {
        return [koboJunkKey() => koboJunk()];
    }
    $keys = array_keys($row);
    $key = $keys[mt_rand(0, count($keys) - 1)];
    match (mt_rand(0, 4)) {
        0 => $row = array_diff_key($row, [$key => true]),                   // drop the key
        1 => $row[$key] = koboJunk(),                                        // wrong type or value
        2 => $row[$key] = null,                                              // null
        3 => $row = [...array_diff_key($row, [$key => true]), koboJunkKey() => $row[$key]], // a bad path
        default => $row[koboJunkKey()] = koboJunk(),                         // junk beside it
    };

    return $row;
}

it('never throws on 500 randomly mangled Kobo rows', function (): void {
    mt_srand(20261030);
    $clean = json_decode((string) file_get_contents(base_path('tests/Fixtures/sources/kobo/valid/clean-visit.json')), true, flags: JSON_THROW_ON_ERROR);
    $own = ['_id', '_uuid', '_submission_time', '__version__', '_submitted_by', '_attachments', 'meta/instanceID', 'meta', 'deviceid'];

    for ($i = 0; $i < 500; $i++) {
        $row = $clean;
        // Sometimes one of Kobo's own fields goes wrong, or the whole row is gone.
        if (mt_rand(0, 4) === 0) {
            $row = mt_rand(0, 9) === 0 ? [] : [...$row, $own[mt_rand(0, count($own) - 1)] => koboJunk()];
        }
        foreach (range(1, mt_rand(1, 6)) as $_) {
            $row = koboMangle($row);
        }

        $parsed = koboParse($row);

        expect($parsed)->toBeInstanceOf(ParsedSubmission::class)
            ->and($parsed->source)->toBe('kobo')
            ->and(ParsedSubmission::fromArray($parsed->toArray())->toArray())->toBe($parsed->toArray());
    }
});
