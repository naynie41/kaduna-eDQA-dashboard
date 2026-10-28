<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Aggregators;

use Illuminate\Support\Facades\DB;

/**
 * Flags still standing, per soft rule (the Data issues summary, D-57): how many accepted
 * assessments carry each code. An assessment with two flags counts under both.
 */
final class SoftFlagAggregator
{
    /**
     * @param  list<string>  $softCodes
     * @return array<string, int> code => assessments carrying it (0 when none)
     */
    public function openCounts(array $softCodes): array
    {
        $counts = array_fill_keys($softCodes, 0);
        if ($softCodes === []) {
            return $counts;
        }

        $rows = DB::table('assessments as a')
            ->crossJoin(DB::raw('jsonb_array_elements_text(a.flags) AS f(code)'))
            ->whereIn('a.status', ['accepted', 'flagged'])
            ->whereIn('f.code', $softCodes)
            ->groupBy('f.code')
            ->selectRaw('f.code, COUNT(DISTINCT a.id) AS n')
            ->get();
        foreach ($rows as $row) {
            $counts[(string) $row->code] = (int) $row->n;
        }

        return $counts;
    }
}
