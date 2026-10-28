<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Assessment\Models\Assessment;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Data issues page's Soft tab (ARCHITECTURE.md §6, D-57): accepted assessments that carry a
 * soft-rule flag still standing (an override removes it), newest first, 25 a page, filtered by
 * the flag's code, the facility's LGA and the round. Flags are assessments.flags (jsonb, GIN).
 */
final class FlaggedAssessmentsQuery
{
    public const PER_PAGE = 25;

    /**
     * @param  list<string>  $softCodes  the soft rules' codes (RuleCatalogue)
     * @param  array{rule?: ?string, round?: int|string|null, lga?: ?string}  $filters
     * @return LengthAwarePaginator<int, Assessment>
     */
    public function page(array $softCodes, array $filters): LengthAwarePaginator
    {
        $rule = $filters['rule'] ?? null;
        $codes = $rule !== null && in_array($rule, $softCodes, true) ? [$rule] : $softCodes;

        $query = Assessment::query()
            ->with(['facility:id,code,name,lga_id', 'facility.lga:id,code,name', 'round:id,year,quarter'])
            ->whereIn('status', ['accepted', 'flagged'])
            ->where(function ($q) use ($codes): void {
                foreach ($codes as $code) {
                    $q->orWhereRaw('flags @> ?::jsonb', [json_encode([$code], JSON_THROW_ON_ERROR)]);
                }
            });
        if (($filters['round'] ?? null) !== null) {
            $query->where('round_id', (int) $filters['round']);
        }
        if (($filters['lga'] ?? null) !== null) {
            $query->whereHas('facility.lga', fn ($q) => $q->where('code', $filters['lga']));
        }

        return $query->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();
    }
}
