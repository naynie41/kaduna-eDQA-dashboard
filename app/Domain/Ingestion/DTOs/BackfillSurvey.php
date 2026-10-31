<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** What `edqa:backfill {source} --survey` found in the source: nothing was written. */
final readonly class BackfillSurvey
{
    /**
     * $rows: by the quarter each visit is held to (declared, else visited) and form version, oldest
     * first; round and window are that quarter's round, null when it has none; outside_window
     * counts the visits its window misses.
     *
     * @param  list<array{year: int, quarter: int, version: string, mapped: bool, round: ?string, window: ?array{0: string, 1: string}, first_visit: string, last_visit: string, outside_window: int, submissions: int}>  $rows
     */
    public function __construct(
        public int $total,
        public array $rows,
    ) {}

    /** @return list<array{year: int, quarter: int, submissions: int}> quarters with submissions and no round yet */
    public function quartersWithoutRound(): array
    {
        return $this->quarters(fn (array $row): bool => $row['round'] === null);
    }

    /** @return list<array{year: int, quarter: int, submissions: int}> quarters whose round is closed: their visits would quarantine as "round closed" */
    public function quartersClosed(): array
    {
        return $this->quarters(fn (array $row): bool => $row['round'] === 'closed');
    }

    /** @return list<array{year: int, quarter: int, window: array{0: string, 1: string}, outside: int, submissions: int}> rounds whose window misses some of their visits, all versions */
    public function windowsMissingVisits(): array
    {
        $rounds = [];
        foreach ($this->rows as $row) {
            if ($row['window'] === null) {
                continue;
            }
            $key = "{$row['year']}Q{$row['quarter']}";
            $rounds[$key] = [
                'year' => $row['year'],
                'quarter' => $row['quarter'],
                'window' => $row['window'],
                'outside' => ($rounds[$key]['outside'] ?? 0) + $row['outside_window'],
                'submissions' => ($rounds[$key]['submissions'] ?? 0) + $row['submissions'],
            ];
        }

        return array_values(array_filter($rounds, fn (array $round): bool => $round['outside'] > 0));
    }

    /** @return list<string> form versions with no field map: their submissions will quarantine */
    public function unmappedVersions(): array
    {
        return array_values(array_unique(array_map(fn (array $row): string => $row['version'], array_filter($this->rows, fn (array $row): bool => ! $row['mapped']))));
    }

    /**
     * @param  callable(array{year: int, quarter: int, version: string, mapped: bool, round: ?string, window: ?array{0: string, 1: string}, first_visit: string, last_visit: string, outside_window: int, submissions: int}): bool  $filter
     * @return list<array{year: int, quarter: int, submissions: int}> with the submissions in each, all versions
     */
    private function quarters(callable $filter): array
    {
        $quarters = [];
        foreach (array_filter($this->rows, $filter) as $row) {
            $key = "{$row['year']}Q{$row['quarter']}";
            $quarters[$key] = ['year' => $row['year'], 'quarter' => $row['quarter'], 'submissions' => ($quarters[$key]['submissions'] ?? 0) + $row['submissions']];
        }

        return array_values($quarters);
    }
}
