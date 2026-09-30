<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Facility\Models\Lga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic LGAs for tests. The real 23 are seeded by name, never by this factory.
 *
 * @extends Factory<Lga>
 */
final class LgaFactory extends Factory
{
    protected $model = Lga::class;

    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999_999);

        return [
            'name' => "Test LGA {$n}",
            'code' => "LGA{$n}",
        ];
    }
}
