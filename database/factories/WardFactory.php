<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ward>
 */
final class WardFactory extends Factory
{
    protected $model = Ward::class;

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999_999);

        return [
            'lga_id' => Lga::factory(),
            'name' => "Test Ward {$n}",
            'code' => "WRD{$n}",
        ];
    }
}
