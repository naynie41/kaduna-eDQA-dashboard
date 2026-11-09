<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-62 / Phase 5 step 2: an assessment whose submission was deleted at source can be withdrawn by an
 * administrator. Its status becomes 'withdrawn' (a CHECK on the statuses, which had none), it
 * counts in no aggregator or round_aggregates (they read accepted and flagged only), and the
 * facility may be assessed again in that round: one live assessment per facility and round, so the
 * unique index skips withdrawn rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE assessments ADD CONSTRAINT assessments_status_valid CHECK (status IN ('accepted', 'flagged', 'withdrawn'))");
        DB::statement('ALTER TABLE assessments DROP CONSTRAINT assessments_round_id_facility_id_unique');
        DB::statement("CREATE UNIQUE INDEX assessments_round_facility_live_unique ON assessments (round_id, facility_id) WHERE status <> 'withdrawn'");
    }

    public function down(): void
    {
        // The full unique constraint cannot hold while a withdrawn assessment sits beside a live one
        // for the same facility and round: refuse rather than drop or rewrite either.
        $clash = DB::selectOne("SELECT count(*) AS n FROM assessments w JOIN assessments l ON l.round_id = w.round_id AND l.facility_id = w.facility_id AND l.id <> w.id WHERE w.status = 'withdrawn'");
        if ((int) ($clash->n ?? 0) > 0) {
            throw new RuntimeException('A withdrawn assessment shares its facility and round with another: resolve it before rolling this migration back.');
        }

        DB::statement('DROP INDEX IF EXISTS assessments_round_facility_live_unique');
        DB::statement('ALTER TABLE assessments ADD CONSTRAINT assessments_round_id_facility_id_unique UNIQUE (round_id, facility_id)');
        DB::statement('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS assessments_status_valid');
    }
};
