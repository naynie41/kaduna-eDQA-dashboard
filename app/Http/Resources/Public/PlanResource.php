<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Domain\Reporting\DTOs\Plan;
use App\Domain\Reporting\DTOs\PlanRow;
use App\Domain\Reporting\DTOs\PlanSummary;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Http\Request;

/**
 * The implementation plan, read-only (ARCHITECTURE.md §9.2.6). Owners are shown as roles, never a
 * personal name (D-79): a stored owner that is not one of the roles (lang/en/plan.php owners) is
 * shown as the dimension's default role.
 *
 * @property Plan $resource
 */
final class PlanResource extends PublicResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'round' => $this->resource->round,
            'threshold' => $this->resource->threshold,
            'actions' => array_map(fn (PlanRow $row): array => [
                'lgaId' => $row->lgaId,
                'code' => $row->code,
                'name' => $row->name,
                'overall' => self::score($row->overall),
                'dimension' => $row->dimension->value,
                'score' => self::score($row->score),
                'action' => $row->action,
                'owner' => self::role($row->owner, $row->dimension),
                'reviewDate' => $row->reviewDate,
                'reviewRound' => $row->reviewRound,
                'stored' => $row->stored,
            ], $this->resource->actions),
            'clear' => LgaScoreResource::many($this->resource->clear),
            'summary' => array_map(fn (PlanSummary $summary): array => [
                'dimension' => $summary->dimension->value,
                'action' => $summary->action,
                'meaning' => $summary->meaning,
                'owner' => self::role($summary->owner, $summary->dimension),
                'count' => $summary->count,
            ], $this->resource->summary),
        ];
    }

    /** The owner if it is one of the roles, else the dimension's default role (D-79). */
    private static function role(string $owner, Dimension $dimension): string
    {
        /** @var array<string, string> $roles */
        $roles = (array) __('plan.owners');
        if (in_array($owner, $roles, true)) {
            return $owner;
        }

        $default = (string) config("edqa.plan.default_owners.{$dimension->value}");

        return $roles[$default] ?? '';
    }
}
