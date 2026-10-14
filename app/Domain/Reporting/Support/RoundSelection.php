<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Models\Round;

/**
 * Which rounds a filter puts in view, in time order. Cached (rounds change rarely; saving one
 * flushes it).
 *
 * - current(): what the summaries cover. Year and quarter: that round. Year: its rounds. No
 *   filter: the latest round. (Q-28: across several rounds a facility's score is the mean of
 *   its assessments in them.)
 * - series(): what the trend and Visuals cover. Year and quarter: that round. Year: its rounds.
 *   No filter: every round. withoutQuarter: the year's rounds even when a quarter is chosen.
 */
final class RoundSelection
{
    public function __construct(
        private readonly RoundCache $cache,
    ) {}

    /** @return list<Round> */
    public function current(ReportFilter $filter): array
    {
        if ($filter->year === null) {
            return $this->cache->rememberGlobal('rounds:latest', fn (): array => array_values(
                Round::query()->orderByDesc('year')->orderByDesc('quarter')->limit(1)->get()->all(),
            ));
        }

        return $this->series($filter);
    }

    /** @return list<Round> */
    public function series(ReportFilter $filter, bool $withoutQuarter = false): array
    {
        $quarter = $withoutQuarter ? null : $filter->quarter;

        return $this->cache->rememberGlobal("rounds:{$filter->year}:{$quarter}", fn (): array => array_values(
            Round::query()
                ->when($filter->year !== null, fn ($q) => $q->where('year', $filter->year))
                ->when($quarter !== null, fn ($q) => $q->where('quarter', $quarter))
                ->orderBy('year')->orderBy('quarter')
                ->get()->all(),
        ));
    }

    /**
     * @param  list<Round>  $rounds
     * @return list<int>
     */
    public static function ids(array $rounds): array
    {
        return array_map(fn (Round $round): int => $round->id, $rounds);
    }
}
