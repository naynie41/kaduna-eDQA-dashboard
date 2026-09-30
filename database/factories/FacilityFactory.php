<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Public primary facilities by default, in the same LGA as their ward.
 *
 * @extends Factory<Facility>
 */
final class FacilityFactory extends Factory
{
    protected $model = Facility::class;

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999_999);

        return [
            'code' => "KD/TST/{$n}",
            'name' => 'PHC '.fake()->lastName(),
            'ward_id' => Ward::factory(),
            // The denormalised LGA always matches the ward's.
            'lga_id' => fn (array $attributes): int => (int) Ward::query()->whereKey($attributes['ward_id'])->value('lga_id'),
            'level' => FacilityLevel::Primary,
            'ownership' => Ownership::Public,
            'is_active' => true,
            'lat' => fake()->latitude(9.0, 11.5),
            'lng' => fake()->longitude(6.5, 8.8),
        ];
    }

    public function private(): self
    {
        return $this->state(['ownership' => Ownership::Private]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function level(FacilityLevel $level): self
    {
        return $this->state(['level' => $level]);
    }

    public function inWard(Ward $ward): self
    {
        return $this->state(['ward_id' => $ward->id, 'lga_id' => $ward->lga_id]);
    }
}
