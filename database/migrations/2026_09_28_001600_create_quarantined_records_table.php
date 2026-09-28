<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Submissions that failed a hard rule, with every failure: [{code, severity, detail}].
// Reporting never reads this table (CLAUDE.md hard rule 6). status and resolution mirror
// App\Domain\Validation\Enums\{QuarantineStatus,QuarantineResolution}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quarantined_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('submissions');
            $table->string('instance_id');
            $table->foreignId('round_id')->nullable()->constrained('rounds');
            $table->string('facility_ref')->nullable();
            $table->string('lga_ref')->nullable();
            $table->jsonb('payload');
            $table->jsonb('failures');
            $table->string('status')->default('open');
            $table->string('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'round_id']);
        });

        DB::statement("ALTER TABLE quarantined_records ADD CONSTRAINT quarantined_records_status_valid CHECK (status IN ('open', 'resolved'))");
        DB::statement("ALTER TABLE quarantined_records ADD CONSTRAINT quarantined_records_resolution_valid CHECK (resolution IS NULL OR resolution IN ('reprocessed', 'rejected', 'superseded'))");
        // Data issues page groups by rule code inside failures.
        DB::statement('CREATE INDEX quarantined_records_failures_gin ON quarantined_records USING gin (failures)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS quarantined_records_failures_gin');
        DB::statement('ALTER TABLE quarantined_records DROP CONSTRAINT IF EXISTS quarantined_records_resolution_valid');
        DB::statement('ALTER TABLE quarantined_records DROP CONSTRAINT IF EXISTS quarantined_records_status_valid');
        Schema::dropIfExists('quarantined_records');
    }
};
