<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The 23 LGAs of Kaduna State (ARCHITECTURE.md §3). The only way rows reach `lgas`: the app
 * role cannot insert (CLAUDE.md hard rule 9, post-migrate-grants.sql). Runs in every
 * environment, as the migrator. Idempotent: upsert by name.
 */
final class LgaSeeder extends Seeder
{
    /**
     * PROVISIONAL codes: three-letter abbreviations chosen for the portal. Replace them with
     * the national facility registry's LGA codes once confirmed (§3, open question Q-15).
     */
    public const LGAS = [
        'Birnin Gwari' => 'BGW',
        'Chikun' => 'CHK',
        'Giwa' => 'GWA',
        'Igabi' => 'IGB',
        'Ikara' => 'IKR',
        'Jaba' => 'JBA',
        "Jema'a" => 'JMA',
        'Kachia' => 'KCH',
        'Kaduna North' => 'KDN',
        'Kaduna South' => 'KDS',
        'Kagarko' => 'KGK',
        'Kajuru' => 'KJR',
        'Kaura' => 'KRA',
        'Kauru' => 'KRU',
        'Kubau' => 'KBU',
        'Kudan' => 'KDA',
        'Lere' => 'LRE',
        'Makarfi' => 'MKF',
        'Sabon Gari' => 'SBG',
        'Sanga' => 'SNG',
        'Soba' => 'SBA',
        'Zangon Kataf' => 'ZKF',
        'Zaria' => 'ZAR',
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::LGAS as $name => $code) {
            $rows[] = ['name' => $name, 'code' => $code];
        }

        DB::table('lgas')->upsert($rows, ['name'], ['code']);
    }
}
