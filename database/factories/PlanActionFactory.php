<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Facility\Models\Lga;
use App\Domain\Plan\Enums\PlanActionStatus;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanAction>
 */
final class PlanActionFactory extends Factory
{
    protected $model = PlanAction::class;

    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'lga_id' => Lga::factory(),
            'dimension' => fake()->randomElement(Dimension::cases()),
            'action_text' => 'Supportive supervision on register completion',
            'assigned_to' => 'LGA M&E officer',
            'due_date' => now()->addMonth()->toDateString(),
            'status' => PlanActionStatus::NotStarted,
        ];
    }
}
