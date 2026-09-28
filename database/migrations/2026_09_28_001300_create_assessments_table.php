<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One visit to one facility in one round, created only from a submission that passed every
// hard rule. An ODK edit updates it in place (audited), so (round, facility) stays unique.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('round_id')->constrained('rounds');
            $table->foreignId('facility_id')->constrained('facilities');
            $table->foreignId('submission_id')->constrained('submissions');
            $table->string('assessor_name');
            $table->string('device_id')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');
            $table->jsonb('gps')->nullable();
            $table->string('status');
            $table->jsonb('flags')->default(DB::raw("'[]'::jsonb"));
            $table->timestampsTz();
            $table->unique(['round_id', 'facility_id']);
        });

        DB::statement('ALTER TABLE assessments ADD CONSTRAINT visit_dates_ordered CHECK (ended_at >= started_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE assessments DROP CONSTRAINT IF EXISTS visit_dates_ordered');
        Schema::dropIfExists('assessments');
    }
};
