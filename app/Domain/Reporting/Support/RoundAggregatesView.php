<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Round\Models\Round;
use Illuminate\Support\Facades\DB;

/**
 * When an aggregator may answer from round_aggregates instead of its own SQL (§4: "filters not
 * covered by the view are answered by Aggregator SQL"). A filter maps onto view rows when the
 * view is enabled (edqa.aggregation.read_view) and populated, and the answer is for one round:
 * the owner filter is owner_type, the level filter is level, and the LGA filter the 'lga' scope.
 *
 * The view is reconciled with the aggregator SQL to 1e-9; between a score and the debounced
 * refresh it can lag by up to a minute (RefreshRoundAggregatesJob), then the round cache flushes.
 */
final class RoundAggregatesView
{
    /** Picks the weakest of the three dimension columns; ties go to Availability, then Consistency. */
    public const WEAKEST = <<<'SQL'
        CASE
            WHEN availability IS NULL THEN NULL
            WHEN availability <= consistency AND availability <= validity THEN 'availability'
            WHEN consistency <= validity THEN 'consistency'
            ELSE 'validity'
        END
        SQL;

    public function usable(): bool
    {
        if (config('edqa.aggregation.read_view', true) !== true) {
            return false;
        }

        return (bool) DB::selectOne("SELECT ispopulated FROM pg_matviews WHERE schemaname = 'public' AND matviewname = 'round_aggregates'")?->ispopulated;
    }

    /** @param  list<Round>  $rounds */
    public function covers(array $rounds): bool
    {
        return count($rounds) === 1 && $this->usable();
    }

    public static function ownerType(ReportFilter $filter): string
    {
        return $filter->owner->value ?? 'all';
    }

    public static function level(ReportFilter $filter): string
    {
        return $filter->level->value ?? 'all';
    }

    /**
     * The scope a summary reads: the filtered LGA's row, or the state's.
     *
     * @return array{0: string, 1: string, 2: list<mixed>} scope_type, scope_id SQL, bindings
     */
    public static function scope(ReportFilter $filter): array
    {
        return $filter->lga === null
            ? ['state', '0', []]
            : ['lga', '(SELECT id FROM lgas WHERE code = ?)', [$filter->lga]];
    }
}
