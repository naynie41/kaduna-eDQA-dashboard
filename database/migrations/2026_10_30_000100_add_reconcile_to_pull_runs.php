<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The reconcile sweep (ARCHITECTURE.md §5.3, D-61, D-62) records its progress in pull_runs as
// trigger 'reconcile', with the submissions it found deleted at source in deletions_detected.
// down() files any reconcile runs under 'scheduled' (the sweep is scheduled nightly) so the older
// constraint holds again.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE pull_runs DROP CONSTRAINT IF EXISTS pull_runs_trigger_type_valid');
        DB::statement("ALTER TABLE pull_runs ADD CONSTRAINT pull_runs_trigger_type_valid CHECK (trigger_type IN ('scheduled', 'manual', 'webhook', 'backfill', 'reconcile'))");
        DB::statement('ALTER TABLE pull_runs ADD COLUMN deletions_detected integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE pull_runs ADD CONSTRAINT pull_runs_deletions_detected_nonnegative CHECK (deletions_detected >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pull_runs DROP COLUMN deletions_detected');
        DB::table('pull_runs')->where('trigger_type', 'reconcile')->update(['trigger_type' => 'scheduled']);
        DB::statement('ALTER TABLE pull_runs DROP CONSTRAINT IF EXISTS pull_runs_trigger_type_valid');
        DB::statement("ALTER TABLE pull_runs ADD CONSTRAINT pull_runs_trigger_type_valid CHECK (trigger_type IN ('scheduled', 'manual', 'webhook', 'backfill'))");
    }
};
