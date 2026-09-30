<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Chooses seeders by environment.
 *   production        the 23 LGAs and scoring rule version 1 only; real facilities come from
 *                     the signed-off master list, rounds and accounts from administrators
 *   local, staging    + synthetic wards and facilities, four rounds, demo assessments
 *   anything else     + synthetic wards, facilities and rounds (no demo assessments)
 * No accounts are seeded anywhere: administrators are created by `edqa:admin:create`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([LgaSeeder::class, ScoringRuleVersionSeeder::class]);

        if (app()->environment('production')) {
            return;
        }

        $this->call([WardSeeder::class, FacilitySeeder::class, RoundSeeder::class]);

        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoAssessmentSeeder::class);
        }
    }
}
