<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemResponse>
 */
final class ItemResponseFactory extends Factory
{
    protected $model = ItemResponse::class;

    public function definition(): array
    {
        return [
            'assessment_id' => Assessment::factory(),
            'dimension' => Dimension::Availability,
            'month_slot' => 1,
            'item_code' => 'item_'.fake()->unique()->numberBetween(1, 999_999),
            'value' => 'yes',
            'is_applicable' => true,
        ];
    }
}
