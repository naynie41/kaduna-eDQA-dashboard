<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** What `edqa:backfill --survey` found in ODK: nothing was written. */
final readonly class BackfillSurvey
{
    /**
     * $rows: by visit quarter and form version, oldest first; round is that quarter's round
     * status, null when it has none.
     *
     * @param  list<array{year: int, quarter: int, version: string, mapped: bool, round: ?string, submissions: int}>  $rows
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

    /** @return list<string> form versions with no field map: their submissions will quarantine */
    public function unmappedVersions(): array
    {
        return array_values(array_unique(array_map(fn (array $row): string => $row['version'], array_filter($this->rows, fn (array $row): bool => ! $row['mapped']))));
    }

    /**
     * @param  callable(array{year: int, quarter: int, version: string, mapped: bool, round: ?string, submissions: int}): bool  $filter
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
