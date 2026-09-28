<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Derived scores, one per (assessment, dimension, month slot, rule version). rule_version_id is
// part of the unique key so old-version scores stay readable after a rescore (D-05).
// score_in_range is the database backstop for "a score that cannot be true never reaches a
// chart": the live report's 347.66 is rejected here even if every line of PHP is wrong.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('dimension');
            $table->smallInteger('month_slot');
            $table->decimal('score', 5, 2)->nullable();   // null when the slot is all N/A
            $table->foreignId('rule_version_id')->constrained('scoring_rule_versions');
            $table->timestampTz('computed_at');
            // Named explicitly: the generated name exceeds Postgres's 63-character limit and
            // would be silently truncated.
            $table->unique(['assessment_id', 'dimension', 'month_slot', 'rule_version_id'], 'assessment_scores_slot_version_unique');
        });

        DB::statement('ALTER TABLE assessment_scores ADD CONSTRAINT score_in_range CHECK (score IS NULL OR (score >= 0 AND score <= 100))');
        DB::statement('ALTER TABLE assessment_scores ADD CONSTRAINT score_slot_valid CHECK (month_slot BETWEEN 1 AND 3)');
        DB::statement("ALTER TABLE assessment_scores ADD CONSTRAINT assessment_scores_dimension_valid CHECK (dimension IN ('availability', 'consistency', 'validity'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assessment_scores DROP CONSTRAINT IF EXISTS assessment_scores_dimension_valid');
        DB::statement('ALTER TABLE assessment_scores DROP CONSTRAINT IF EXISTS score_slot_valid');
        DB::statement('ALTER TABLE assessment_scores DROP CONSTRAINT IF EXISTS score_in_range');
        Schema::dropIfExists('assessment_scores');
    }
};
