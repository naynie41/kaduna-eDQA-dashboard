<?php

declare(strict_types=1);

namespace App\Http\Resources\Scoring;

use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One rule version. status: draft (unpublished), current (the highest published) or published
 * (superseded, still read by the closed rounds pinned to it).
 *
 * @mixin ScoringRuleVersion
 */
final class ScoringRuleVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'status' => match (true) {
                $this->published_at === null => 'draft',
                $this->id === app(CurrentRuleVersion::class)->id() => 'current',
                default => 'published',
            },
            'config' => $this->config,
            'created_by' => $this->created_by,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_reason' => $this->published_reason,
        ];
    }
}
