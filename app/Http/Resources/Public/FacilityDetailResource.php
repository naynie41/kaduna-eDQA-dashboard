<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\FacilityDetail;
use App\Domain\Scoring\DTOs\ScoreValue;
use Illuminate\Http\Request;

/**
 * The public facility drawer (SECURITY.md §3.1, ARCHITECTURE.md §9.2.7): the facility's name, LGA,
 * ward, level and ownership; the score card of a closed round; its pass / fail / N/A item results;
 * the round history. Nothing else: no assessor, flags, attachments, audit, source, instance ID,
 * coordinates (Q-20), facility id or code. The detail is computed for the Public audience
 * (closed rounds only, FacilityDetailAggregator); this is the whitelist on top.
 *
 * @property FacilityDetail $resource
 */
final class FacilityDetailResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $facility = $this->resource->facility;
        $round = $this->resource->round;

        return [
            'facility' => [
                'name' => $facility['name'],
                'lga' => $facility['lga'],
                'ward' => $facility['ward'],
                'level' => $facility['level'],
                'level_label' => $facility['level_label'],
                'ownership' => $facility['ownership'],
                'ownership_label' => $facility['ownership_label'],
            ],
            'round' => $round === null ? null : [
                'id' => $round['id'],
                'label' => $round['label'],
                'year' => $round['year'],
                'quarter' => $round['quarter'],
            ],
            'scores' => array_map(fn (ScoreValue $score): array => self::score($score), $this->resource->scores),
            'items' => array_map(fn (array $group): array => [
                'dimension' => $group['dimension'],
                'dimension_label' => $group['dimension_label'],
                'slot' => $group['slot'],
                'month' => $group['month'],
                'score' => self::score($group['score']),
                'items' => array_map(fn (array $item): array => [
                    'code' => $item['code'],
                    'label' => $item['label'],
                    'result' => $item['result'],
                    'checks' => $item['checks'] ?? null,
                ], $group['items']),
            ], $this->resource->items),
            'history' => array_map(fn (array $point): array => [
                'round_id' => $point['round_id'],
                'label' => $point['label'],
                'overall' => self::score($point['overall']),
                'selected' => $point['selected'],
            ], $this->resource->history),
        ];
    }
}
