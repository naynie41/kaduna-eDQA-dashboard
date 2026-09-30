<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Synthetic\SyntheticFacilityList;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SYNTHETIC wards (local and staging only), from SyntheticFacilityList. Idempotent: upsert
 * by (lga_id, name).
 */
final class WardSeeder extends Seeder
{
    public function run(SyntheticFacilityList $list): void
    {
        $lgaIds = DB::table('lgas')->pluck('id', 'name');

        $rows = array_map(fn (array $ward): array => [
            'lga_id' => $lgaIds[$ward['lga']],
            'name' => $ward['name'],
            'code' => $ward['code'],
        ], $list->wards());

        DB::table('wards')->upsert($rows, ['lga_id', 'name'], ['code']);
    }
}
