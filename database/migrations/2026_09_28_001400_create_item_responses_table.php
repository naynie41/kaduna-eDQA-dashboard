<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One scored question's answer. Scores are always derived from these, never accepted from
// input (CLAUDE.md hard rule 2). dimension mirrors App\Domain\Scoring\Enums\Dimension.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained('assessments')->cascadeOnDelete();
            $table->string('dimension');
            $table->smallInteger('month_slot');
            $table->string('item_code');
            $table->string('value');
            $table->boolean('is_applicable');
            $table->index(['assessment_id', 'dimension', 'month_slot']);
        });

        DB::statement('ALTER TABLE item_responses ADD CONSTRAINT month_slot_valid CHECK (month_slot BETWEEN 1 AND 3)');
        DB::statement("ALTER TABLE item_responses ADD CONSTRAINT item_responses_dimension_valid CHECK (dimension IN ('availability', 'consistency', 'validity'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE item_responses DROP CONSTRAINT IF EXISTS item_responses_dimension_valid');
        DB::statement('ALTER TABLE item_responses DROP CONSTRAINT IF EXISTS month_slot_valid');
        Schema::dropIfExists('item_responses');
    }
};
