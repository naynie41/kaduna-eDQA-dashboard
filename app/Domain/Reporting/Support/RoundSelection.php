<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Models\Round;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which rounds a filter puts in view, in time order. Cached (rounds change rarely; saving one
 * flushes it). Every aggregator, the facility query and the round_aggregates reads take their
 * rounds from here, so this is where PublicScope holds the public to closed rounds (D-77); the
 * scope is in every cache key.
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
            $scope = PublicScope::key($filter->audience);

            return $this->load($this->cache->rememberGlobal("rounds:{$scope}:latest", fn (): array => self::rowsOf(
                PublicScope::apply(Round::query(), $filter->audience)->orderByDesc('year')->orderByDesc('quarter')->limit(1),
            )));
        }

        return $this->series($filter);
    }

    /** @return list<Round> */
    public function series(ReportFilter $filter, bool $withoutQuarter = false): array
    {
        $quarter = $withoutQuarter ? null : $filter->quarter;
        $scope = PublicScope::key($filter->audience);

        return $this->load($this->cache->rememberGlobal("rounds:{$scope}:{$filter->year}:{$quarter}", fn (): array => self::rowsOf(
            PublicScope::apply(Round::query(), $filter->audience)
                ->when($filter->year !== null, fn ($q) => $q->where('year', $filter->year))
                ->when($quarter !== null, fn ($q) => $q->where('quarter', $quarter))
                ->orderBy('year')->orderBy('quarter'),
        )));
    }

    /**
     * Only each round's raw column values are cached, never the model: a model never goes through
     * unserialize (RoundCache::SERIALIZABLE), and hydrating needs no query.
     *
     * @param  Builder<Round>  $query
     * @return list<array<string, mixed>>
     */
    private static function rowsOf(Builder $query): array
    {
        return array_values($query->get()->map(fn (Round $round): array => $round->getAttributes())->all());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Round>
     */
    private function load(array $rows): array
    {
        return array_values(Round::hydrate($rows)->all());
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
