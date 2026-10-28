<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Assessment\Models\Assessment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Soft-tab row (D-57): an accepted assessment and the soft flags it still carries, each of
 * which can be overridden with a reason. Informational notes in flags (SLOT_ALL_NA) are left
 * out: they are not flags. Expects facility.lga and round loaded.
 *
 * @mixin Assessment
 */
final class FlaggedAssessmentResource extends JsonResource
{
    /** @param  list<string>  $softCodes */
    public function __construct(Assessment $assessment, private readonly array $softCodes)
    {
        parent::__construct($assessment);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var list<string> $flags */
        $flags = $this->flags;

        return [
            'id' => $this->id,
            'facility' => ['id' => $this->facility->id, 'code' => $this->facility->code, 'name' => $this->facility->name],
            'lga' => $this->facility->lga === null ? null : ['code' => $this->facility->lga->code, 'name' => $this->facility->lga->name],
            'round' => ['id' => $this->round->id, 'year' => $this->round->year, 'quarter' => $this->round->quarter],
            'assessor' => $this->assessor_name,
            'started_at' => $this->started_at->toIso8601String(),
            'flags' => array_values(array_map(
                fn (string $code): array => ['code' => $code, 'name' => (string) __("validation_rules.{$code}.name")],
                array_filter($flags, fn (string $code): bool => in_array($code, $this->softCodes, true)),
            )),
        ];
    }
}
