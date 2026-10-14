<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// rescore_runs: one row per rescore of a round (ARCHITECTURE.md §7 Rescore), the history and live
// progress the admin pages read; job_batches holds the queue's own view and is pruned.
// completed_chunks makes progress idempotent: a chunk delivered twice is counted once.
// trigger mirrors App\Domain\Scoring\Enums\RescoreTrigger, status RescoreStatus.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rescore_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('batch_id')->nullable()->unique();
            $table->foreignId('round_id')->constrained('rounds');
            $table->foreignId('rule_version_id')->constrained('scoring_rule_versions');
            $table->string('trigger');
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->text('reason')->nullable();
            $table->string('status');
            $table->integer('total_assessments')->default(0);
            $table->integer('processed_assessments')->default(0);
            $table->integer('changed_scores')->default(0);
            $table->integer('changed_flags')->default(0);
            $table->integer('unscorable')->default(0);
            $table->integer('failed_chunks')->default(0);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['round_id', 'started_at']);
        });

        DB::statement('ALTER TABLE rescore_runs ADD COLUMN completed_chunks integer[] NOT NULL DEFAULT \'{}\'');
        DB::statement("ALTER TABLE rescore_runs ADD CONSTRAINT rescore_runs_trigger_valid CHECK (trigger IN ('publish', 'quarantine', 'correction', 'manual', 'console'))");
        DB::statement("ALTER TABLE rescore_runs ADD CONSTRAINT rescore_runs_status_valid CHECK (status IN ('running', 'succeeded', 'failed'))");
        DB::statement('ALTER TABLE rescore_runs ADD CONSTRAINT rescore_runs_counts_valid CHECK (total_assessments >= 0 AND processed_assessments >= 0 AND changed_scores >= 0 AND changed_flags >= 0 AND unscorable >= 0 AND failed_chunks >= 0 AND processed_assessments <= total_assessments)');
        DB::statement("ALTER TABLE rescore_runs ADD CONSTRAINT rescore_runs_manual_has_reason CHECK (trigger <> 'manual' OR (reason IS NOT NULL AND requested_by IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('rescore_runs');
    }
};
