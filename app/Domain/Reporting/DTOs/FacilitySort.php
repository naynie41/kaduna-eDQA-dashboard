<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Reporting\Enums\Metric;
use InvalidArgumentException;

/**
 * The facility table's metric and sort (ARCHITECTURE.md §9.2.2). Columns are a fixed list, so a
 * sort can never put request text into SQL. Default: the overall score, weakest first.
 */
final readonly class FacilitySort
{
    public const COLUMNS = ['facility', 'lga', 'ward', 'owner', 'assessed', 'm1', 'm2', 'm3', 'score', 'overall'];

    public function __construct(
        public Metric $metric,
        public string $column,
        public string $direction,
    ) {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Cannot sort facilities by '{$column}'.");
        }
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Sort direction must be asc or desc, not '{$direction}'.");
        }
    }

    public static function default(Metric $metric = Metric::Overall): self
    {
        return new self($metric, 'score', 'asc');
    }

    public function cacheKey(): string
    {
        return "{$this->metric->value}:{$this->column}:{$this->direction}";
    }
}
