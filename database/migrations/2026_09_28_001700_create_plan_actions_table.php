<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Implementation-plan rows: one action per round, LGA and dimension, with an editable owner,
// due date and status (D-10). dimension and status mirror the Dimension and PlanActionStatus
// enums.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('round_id')->constrained('rounds');
            $table->foreignId('lga_id')->constrained('lgas');
            $table->string('dimension');
            $table->text('action_text');
            $table->string('assigned_to');
            $table->date('due_date');
            $table->string('status')->default('not_started');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['round_id', 'lga_id', 'dimension']);
        });

        DB::statement("ALTER TABLE plan_actions ADD CONSTRAINT plan_actions_dimension_valid CHECK (dimension IN ('availability', 'consistency', 'validity'))");
        DB::statement("ALTER TABLE plan_actions ADD CONSTRAINT plan_actions_status_valid CHECK (status IN ('not_started', 'in_progress', 'done'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE plan_actions DROP CONSTRAINT IF EXISTS plan_actions_status_valid');
        DB::statement('ALTER TABLE plan_actions DROP CONSTRAINT IF EXISTS plan_actions_dimension_valid');
        Schema::dropIfExists('plan_actions');
    }
};
