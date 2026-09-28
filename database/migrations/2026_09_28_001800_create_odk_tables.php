<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ODK pull bookkeeping (ARCHITECTURE.md §5.3, §5.6):
//   odk_form_syncs   the pull cursor; last_pulled_at advances only after a whole page commits
//   odk_pull_runs    one row per pull, powering the pull history and live progress
//   odk_attachments  deferred attachment downloads (backfill)
// trigger_type mirrors App\Domain\Ingestion\Enums\PullTrigger.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odk_form_syncs', function (Blueprint $table): void {
            $table->id();
            $table->integer('project_id');
            $table->string('form_id');
            $table->timestampTz('last_pulled_at')->nullable();
            $table->string('last_instance_id')->nullable();
            $table->integer('backfill_skip')->default(0);
            $table->text('last_error')->nullable();
            $table->integer('submission_count')->default(0);
            $table->timestampsTz();
        });

        Schema::create('odk_pull_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('triggered_by')->nullable()->constrained('users');
            $table->string('trigger_type');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->integer('fetched')->default(0);
            $table->integer('accepted')->default(0);
            $table->integer('quarantined')->default(0);
            $table->integer('duplicates')->default(0);
            $table->text('error')->nullable();
            $table->string('outcome')->nullable();
        });

        DB::statement("ALTER TABLE odk_pull_runs ADD CONSTRAINT odk_pull_runs_trigger_type_valid CHECK (trigger_type IN ('scheduled', 'manual', 'webhook'))");

        Schema::create('odk_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions');
            $table->string('filename');
            $table->string('mime');
            $table->bigInteger('size');
            $table->string('path')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->string('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odk_attachments');
        DB::statement('ALTER TABLE IF EXISTS odk_pull_runs DROP CONSTRAINT IF EXISTS odk_pull_runs_trigger_type_valid');
        Schema::dropIfExists('odk_pull_runs');
        Schema::dropIfExists('odk_form_syncs');
    }
};
