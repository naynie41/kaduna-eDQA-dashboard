<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ingestion\Actions\SyncSubmissionSources;
use Illuminate\Database\Seeder;

/** The submission_sources rows for every configured source (edqa:sources:sync), in every environment. */
final class SubmissionSourceSeeder extends Seeder
{
    public function run(SyncSubmissionSources $sync): void
    {
        $sync->execute();
    }
}
