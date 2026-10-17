<?php

declare(strict_types=1);

namespace Tests\Support;

use Database\Seeders\LgaSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The fixed database the ODK fixtures run against (tests/Fixtures/README.md): every reference a
 * fixture names exists here with that exact code, and each case's earlier state is here too, so a
 * fixture file alone decides its outcome.
 *
 *   LGAs        the 23 (LgaSeeder): CHK Chikun, ZAR Zaria, …
 *   Ward        CHK-KUJ Kujama, in Chikun
 *   Facilities  in Chikun / Kujama, public primary:
 *               FX-0001 the ordinary facility          FX-0004 deactivated
 *               FX-0002 already assessed in 2026 Q2    FX-0005 A. Bello's seventh visit
 *               FX-0003 scored 40 everywhere in Q1     FX-0006 the 2025.2 visit
 *               FX-0101 … FX-0106 visited by A. Bello (collect:p1) on 14 April 2026 at 08:00
 *   Rounds      2026 Q1 closed (pinned to version 1), 2026 Q2 open (1 April to 30 June)
 *   Rules       scoring rule version 1, published (ScoringRuleVersionSeeder)
 */
final class FixtureWorld
{
    public static function build(): void
    {
        (new LgaSeeder)->run();
        (new ScoringRuleVersionSeeder)->run();
        $version = (int) DB::table('scoring_rule_versions')->value('id');
        $chikun = (int) DB::table('lgas')->where('code', 'CHK')->value('id');
        $kujama = Rows::ward(['lga_id' => $chikun, 'code' => 'CHK-KUJ', 'name' => 'Kujama']);

        $facility = function (string $code, bool $active = true) use ($chikun, $kujama): int {
            return Rows::facility(['code' => $code, 'name' => "Facility {$code}", 'lga_id' => $chikun, 'ward_id' => $kujama, 'is_active' => $active]);
        };
        foreach (['FX-0001', 'FX-0005', 'FX-0006'] as $code) {
            $facility($code);
        }
        $facility('FX-0004', active: false);

        $q1 = Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed', 'rule_version_id' => $version, 'closed_at' => '2026-04-21 17:00:00+01']);
        $q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);

        // FX-0002: already assessed in Q2, so another visit is a duplicate.
        Rows::assessment(['round_id' => $q2, 'facility_id' => $facility('FX-0002'), 'started_at' => '2026-04-08 09:00:00+01', 'ended_at' => '2026-04-08 09:45:00+01']);

        // FX-0003: 40 everywhere in Q1, so 75 now is a jump.
        $earlier = Rows::assessment(['round_id' => $q1, 'facility_id' => $facility('FX-0003'), 'started_at' => '2026-02-10 09:00:00+01', 'ended_at' => '2026-02-10 09:45:00+01']);
        ReportingFixture::scores($earlier, $version, [40, 40, 40], [40, 40, 40], [40, 40, 40]);

        // Six visits by A. Bello on 14 April: a seventh that day is too many.
        foreach (range(1, 6) as $n) {
            Rows::assessment([
                'round_id' => $q2, 'facility_id' => $facility(sprintf('FX-%04d', 100 + $n)),
                'assessor_name' => 'A. Bello', 'device_id' => 'collect:p1',
                'started_at' => '2026-04-14 08:00:00+01', 'ended_at' => '2026-04-14 08:40:00+01',
            ]);
        }
    }
}
