<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The facility master list is the validation authority. Deactivate, never delete.
// CHECK values mirror App\Domain\Facility\Enums\{FacilityLevel,Ownership}; they are written
// out here because a migration must not change when the enum does (add a new migration).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('ward_id')->constrained('wards');
            $table->foreignId('lga_id')->constrained('lgas');
            $table->string('level');
            $table->string('ownership');
            $table->boolean('is_active')->default(true);
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_level_valid CHECK (level IN ('primary', 'secondary', 'tertiary'))");
        DB::statement("ALTER TABLE facilities ADD CONSTRAINT facilities_ownership_valid CHECK (ownership IN ('public', 'private'))");
        // Cascade export and facility pickers only ever read active facilities per LGA.
        DB::statement('CREATE INDEX facilities_lga_id_active_index ON facilities (lga_id) WHERE is_active');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS facilities_lga_id_active_index');
        DB::statement('ALTER TABLE facilities DROP CONSTRAINT IF EXISTS facilities_ownership_valid');
        DB::statement('ALTER TABLE facilities DROP CONSTRAINT IF EXISTS facilities_level_valid');
        Schema::dropIfExists('facilities');
    }
};
