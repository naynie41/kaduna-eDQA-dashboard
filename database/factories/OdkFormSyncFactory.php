<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Ingestion\Models\OdkFormSync;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pull cursor that has never pulled.
 *
 * @extends Factory<OdkFormSync>
 */
final class OdkFormSyncFactory extends Factory
{
    protected $model = OdkFormSync::class;

    public function definition(): array
    {
        return [
            'project_id' => 1,
            'form_id' => 'dqa',
            'backfill_skip' => 0,
            'submission_count' => 0,
        ];
    }
}
