<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The backfill records its progress in odk_pull_runs as trigger 'backfill' (ARCHITECTURE.md §5.6,
// D-53). down() files any backfill runs under 'manual' (an administrator started them) so the
// older constraint holds again.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE odk_pull_runs DROP CONSTRAINT IF EXISTS odk_pull_runs_trigger_type_valid');
        DB::statement("ALTER TABLE odk_pull_runs ADD CONSTRAINT odk_pull_runs_trigger_type_valid CHECK (trigger_type IN ('scheduled', 'manual', 'webhook', 'backfill'))");
    }

    public function down(): void
    {
        DB::table('odk_pull_runs')->where('trigger_type', 'backfill')->update(['trigger_type' => 'manual']);
        DB::statement('ALTER TABLE odk_pull_runs DROP CONSTRAINT IF EXISTS odk_pull_runs_trigger_type_valid');
        DB::statement("ALTER TABLE odk_pull_runs ADD CONSTRAINT odk_pull_runs_trigger_type_valid CHECK (trigger_type IN ('scheduled', 'manual', 'webhook'))");
    }
};
