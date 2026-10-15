<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\RoundCache;
use Illuminate\Support\Facades\DB;

/**
 * What the filter bar offers (ARCHITECTURE.md §9.1): the year and quarter of every round, newest
 * first; the 23 LGAs by name; the levels and owner types with their labels. Lists, no
 * aggregates; the rounds and LGAs are cached until a round or LGA changes.
 */
final class FilterOptionsQuery
{
    public function __construct(
        private readonly RoundCache $cache,
    ) {}

    /**
     * @return array{
     *     rounds: list<array{year: int, quarter: int}>,
     *     lgas: list<array{code: string, name: string}>,
     *     levels: list<array{value: string, label: string}>,
     *     owners: list<array{value: string, label: string}>,
     * }
     */
    public function get(): array
    {
        /** @var array{rounds: list<array{year: int, quarter: int}>, lgas: list<array{code: string, name: string}>} $lists */
        $lists = $this->cache->rememberGlobal('filter-options', fn (): array => [
            'rounds' => array_values(DB::table('rounds')->orderByDesc('year')->orderByDesc('quarter')->get(['year', 'quarter'])
                ->map(fn (object $r): array => ['year' => (int) $r->year, 'quarter' => (int) $r->quarter])->all()),
            'lgas' => array_values(DB::table('lgas')->orderBy('name')->get(['code', 'name'])
                ->map(fn (object $l): array => ['code' => (string) $l->code, 'name' => (string) $l->name])->all()),
        ]);

        return [
            ...$lists,
            'levels' => array_map(fn (FacilityLevel $l): array => ['value' => $l->value, 'label' => $l->label()], FacilityLevel::cases()),
            'owners' => array_map(fn (Ownership $o): array => ['value' => $o->value, 'label' => $o->label()], Ownership::cases()),
        ];
    }
}
