<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-72: a source's own facility references (the KoboToolbox form's slugs) matched to master-list
 * facilities. Reference data, loaded from a client-signed CSV by edqa:facilities:source-refs; one
 * facility per (source, ref).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE facility_source_refs (
                id bigserial PRIMARY KEY,
                source_id bigint NOT NULL REFERENCES submission_sources (id),
                ref varchar(255) NOT NULL,
                facility_id bigint NOT NULL REFERENCES facilities (id),
                created_at timestamptz NULL,
                updated_at timestamptz NULL,
                CONSTRAINT facility_source_refs_source_id_ref_unique UNIQUE (source_id, ref),
                CONSTRAINT facility_source_refs_ref_not_blank CHECK (btrim(ref) <> '')
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS facility_source_refs');
    }
};
