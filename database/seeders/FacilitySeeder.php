<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Synthetic\SyntheticFacilityList;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * SYNTHETIC facilities (local and staging only), from SyntheticFacilityList: 1,957 public and
 * 219 private. Bulk-inserted: seed data is not an administrator's write, so no audit entries.
 * Idempotent: upsert by code.
 */
final class FacilitySeeder extends Seeder
{
    public function run(SyntheticFacilityList $list): void
    {
        $lgaIds = DB::table('lgas')->pluck('id', 'name');
        $wardIds = DB::table('wards')->get(['id', 'lga_id', 'name'])
            ->mapWithKeys(fn (object $w): array => ["{$w->lga_id}|{$w->name}" => $w->id]);
        $now = now();

        $rows = array_map(function (array $facility) use ($lgaIds, $wardIds, $now): array {
            $lgaId = $lgaIds[$facility['lga']];

            return [
                'code' => $facility['code'],
                'name' => $facility['name'],
                'lga_id' => $lgaId,
                'ward_id' => $wardIds["{$lgaId}|{$facility['ward']}"],
                'level' => $facility['level'],
                'ownership' => $facility['ownership'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $list->facilities());

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('facilities')->upsert($chunk, ['code'], ['name', 'lga_id', 'ward_id', 'level', 'ownership', 'updated_at']);
        }
    }
}
