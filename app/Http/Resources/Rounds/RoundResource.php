<?php

declare(strict_types=1);

namespace App\Http\Resources\Rounds;

use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundWindowCheck;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One round for the Rounds admin page. rule_version_id is the pin (set when closed, kept through a
 * reopen); reads_rule_version_id is the version its scores are read under now (D-32), null when
 * no version is published yet.
 *
 * @mixin Round
 */
final class RoundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'quarter' => $this->quarter,
            'label' => RoundWindowCheck::label($this->year, $this->quarter),
            'window_start' => $this->window_start->toDateString(),
            'window_end' => $this->window_end->toDateString(),
            'status' => $this->status->value,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'rule_version_id' => $this->rule_version_id,
            'reads_rule_version_id' => $this->readsVersion(),
            'reopened_reason' => $this->reopened_reason,
        ];
    }

    private function readsVersion(): ?int
    {
        try {
            return app(RuleVersionForRound::class)->id($this->resource);
        } catch (NoPublishedRuleVersion) {
            return null;
        }
    }
}
