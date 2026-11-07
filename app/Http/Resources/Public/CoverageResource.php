<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\Coverage;
use App\Domain\Reporting\DTOs\LevelCoverage;
use App\Domain\Reporting\DTOs\LgaCount;
use Illuminate\Http\Request;

/**
 * Monitor: facilities visited per LGA and coverage by level.
 *
 * @property Coverage $resource
 */
final class CoverageResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'perLga' => array_map(fn (LgaCount $lga): array => [
                'lgaId' => $lga->lgaId,
                'code' => $lga->code,
                'name' => $lga->name,
                'facilities' => $lga->facilities,
            ], $this->resource->perLga),
            'stateMean' => $this->resource->stateMean,
            'stateMeanDisplay' => $this->resource->stateMeanDisplay,
            'byLevel' => array_map(fn (LevelCoverage $level): array => [
                'level' => $level->level->value,
                'facilities' => $level->facilities,
                'overall' => self::score($level->overall),
            ], $this->resource->byLevel),
        ];
    }
}
