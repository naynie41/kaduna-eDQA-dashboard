<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lga_id')->constrained('lgas');
            $table->string('name');
            $table->string('code');
            $table->unique(['lga_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wards');
    }
};
