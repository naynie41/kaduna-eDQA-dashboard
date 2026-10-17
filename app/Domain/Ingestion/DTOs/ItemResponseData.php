<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

use App\Domain\Scoring\Enums\Dimension;
use App\Support\Data\ArrayReader;
use InvalidArgumentException;

/**
 * One scored question as the parser reads it: the raw ODK choice mapped through the scoring rule
 * version's choice_map to pass, fail or na. An answer the map does not know is 'invalid': kept so
 * ITEMS_INCOMPLETE can quarantine it (an invalid answer is a missing one), never scored.
 */
final readonly class ItemResponseData
{
    public const INVALID = 'invalid';

    public const VALUES = ['pass', 'fail', 'na', self::INVALID];

    public function __construct(
        public Dimension $dimension,
        public int $monthSlot,
        public string $itemCode,
        public string $value,
        public bool $isApplicable,
    ) {
        if ($monthSlot < 1 || $monthSlot > 3) {
            throw new InvalidArgumentException("Month slot must be 1 to 3, got {$monthSlot}.");
        }
        if (! in_array($value, self::VALUES, true)) {
            throw new InvalidArgumentException("Item value must be pass, fail, na or invalid, got '{$value}'.");
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $read = new ArrayReader($data, 'item');

        return new self(
            Dimension::from($read->string('dimension')),
            $read->int('month_slot'),
            $read->string('item_code'),
            $read->string('value'),
            $read->bool('is_applicable'),
        );
    }

    /** @return array{dimension: string, month_slot: int, item_code: string, value: string, is_applicable: bool} */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension->value,
            'month_slot' => $this->monthSlot,
            'item_code' => $this->itemCode,
            'value' => $this->value,
            'is_applicable' => $this->isApplicable,
        ];
    }
}
