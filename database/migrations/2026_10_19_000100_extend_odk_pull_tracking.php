<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The pull job (Phase 2B step 4, D-51):
 *   odk_pull_runs.flagged       accepted with soft flags, counted apart from accepted
 *   odk_pull_runs.outcome       succeeded | failed | skipped_not_configured (null while running)
 *   odk_form_syncs.last_edit_at the edits cursor: the newest __system/updatedAt pulled (Q-32)
 *   odk_form_syncs              one row per project and form
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odk_pull_runs', function (Blueprint $table): void {
            $table->integer('flagged')->default(0);
        });
        DB::statement("ALTER TABLE odk_pull_runs ADD CONSTRAINT odk_pull_runs_outcome_valid CHECK (outcome IS NULL OR outcome IN ('succeeded', 'failed', 'skipped_not_configured'))");

        Schema::table('odk_form_syncs', function (Blueprint $table): void {
            $table->timestampTz('last_edit_at')->nullable();
            $table->unique(['project_id', 'form_id']);
        });
    }

    public function down(): void
    {
        Schema::table('odk_form_syncs', function (Blueprint $table): void {
            $table->dropUnique(['project_id', 'form_id']);
            $table->dropColumn('last_edit_at');
        });

        DB::statement('ALTER TABLE odk_pull_runs DROP CONSTRAINT IF EXISTS odk_pull_runs_outcome_valid');
        Schema::table('odk_pull_runs', function (Blueprint $table): void {
            $table->dropColumn('flagged');
        });
    }
};
