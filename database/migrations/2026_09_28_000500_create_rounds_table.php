<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A round is one quarter's assessment window. Constraint names match ARCHITECTURE.md §4.
// status mirrors App\Domain\Round\Enums\RoundStatus (closed = published, D-07).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rounds', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('year');
            $table->smallInteger('quarter');
            $table->date('window_start');
            $table->date('window_end');
            $table->string('status')->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->text('reopened_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['year', 'quarter']);
        });

        DB::statement('ALTER TABLE rounds ADD CONSTRAINT quarter_valid CHECK (quarter BETWEEN 1 AND 4)');
        DB::statement('ALTER TABLE rounds ADD CONSTRAINT window_ordered CHECK (window_end >= window_start)');
        DB::statement("ALTER TABLE rounds ADD CONSTRAINT status_valid CHECK (status IN ('open', 'closed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rounds DROP CONSTRAINT IF EXISTS status_valid');
        DB::statement('ALTER TABLE rounds DROP CONSTRAINT IF EXISTS window_ordered');
        DB::statement('ALTER TABLE rounds DROP CONSTRAINT IF EXISTS quarter_valid');
        Schema::dropIfExists('rounds');
    }
};
