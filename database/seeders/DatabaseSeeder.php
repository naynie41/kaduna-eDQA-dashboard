<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Chooses seeders by environment.
 *   production        the 23 LGAs, scoring rule version 1 and the configured sources only; real facilities come from
 *                     the signed-off master list, rounds and accounts from administrators
 *   local, staging    + synthetic wards and facilities, four rounds, demo assessments, scored
 *                     by the real rescore (closed rounds pinned to version 1)
 *   anything else     + synthetic wards, facilities and rounds (no demo assessments)
 * No accounts are seeded anywhere: administrators are created by `edqa:admin:create`.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([LgaSeeder::class, ScoringRuleVersionSeeder::class, SubmissionSourceSeeder::class]);

        if (app()->environment('production')) {
            return;
        }

        $this->call([WardSeeder::class, FacilitySeeder::class, RoundSeeder::class]);

        if (app()->environment(['local', 'staging'])) {
            $this->call([DemoAssessmentSeeder::class, DemoScoringSeeder::class]);
        }
    }
}
