<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * A small hand-computed dataset for the aggregator tests: 3 LGAs, 7 facilities, odd numbers.
 * Every expected figure in AggregatorsTest is worked out from the table below.
 *
 *   LGA      Fac  Owner    Level      Availability   Consistency  Validity     Overall
 *   Alpha    F1   public   primary    90 90 90       80 80 80     70 70 70     80
 *   Alpha    F2   private  primary    60 60 60       50 50 50     100 100 100  70
 *   Alpha    F3   public   secondary  95 85 75 (85)  90 90 90     85 85 85     86.6667
 *   Bravo    F4   public   primary    100 ×3         100 ×3       100 ×3       100
 *   Bravo    F5   public   tertiary   40 ×3          55 ×3        61 ×3        52
 *   Charlie  F6   private  secondary  77 ×3          88 ×3        99 ×3        88
 *   Charlie  F7   public   primary    73 ×3          74 ×3        75 ×3        74
 *
 * Round 2026 Q2 (open) holds all seven. Noise that must be ignored: F1 also scored 10 everywhere
 * in 2026 Q1 (closed, pinned to v1), and F1's Q2 assessment has a second set of scores under an
 * older published version.
 */
final class ReportingFixture
{
    public const CONFIG = [
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => [],
        'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ];

    /** @var array<string, int> */
    public array $lgas = [];

    /** @var array<string, int> */
    public array $facilities = [];

    public int $q1;

    public int $q2;

    public int $v1;

    public int $v2;

    public static function build(): self
    {
        $f = new self;
        $f->v1 = Rows::ruleVersion(['version' => 1, 'config' => json_encode(self::CONFIG), 'published_at' => now()->subYear()]);
        $f->v2 = Rows::ruleVersion(['version' => 2, 'config' => json_encode(self::CONFIG), 'published_at' => now()]);
        $f->q1 = Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed', 'rule_version_id' => $f->v1]);
        $f->q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);

        foreach (['AAA' => 'Alpha', 'BBB' => 'Bravo', 'CCC' => 'Charlie'] as $code => $name) {
            $f->lgas[$code] = Rows::lga(['code' => $code, 'name' => $name]);
        }

        $rows = [
            'F1' => ['AAA', 'public', 'primary', [90, 90, 90], [80, 80, 80], [70, 70, 70], '2026-04-10 09:00:00+01'],
            'F2' => ['AAA', 'private', 'primary', [60, 60, 60], [50, 50, 50], [100, 100, 100], '2026-04-11 09:00:00+01'],
            'F3' => ['AAA', 'public', 'secondary', [95, 85, 75], [90, 90, 90], [85, 85, 85], '2026-05-02 09:00:00+01'],
            'F4' => ['BBB', 'public', 'primary', [100, 100, 100], [100, 100, 100], [100, 100, 100], '2026-05-20 09:00:00+01'],
            'F5' => ['BBB', 'public', 'tertiary', [40, 40, 40], [55, 55, 55], [61, 61, 61], '2026-06-01 09:00:00+01'],
            'F6' => ['CCC', 'private', 'secondary', [77, 77, 77], [88, 88, 88], [99, 99, 99], '2026-06-15 09:00:00+01'],
            'F7' => ['CCC', 'public', 'primary', [73, 73, 73], [74, 74, 74], [75, 75, 75], '2026-06-28 09:00:00+01'],
        ];

        foreach ($rows as $name => [$lga, $owner, $level, $a, $c, $v, $started]) {
            $lgaId = $f->lgas[$lga];
            $f->facilities[$name] = Rows::facility([
                'code' => "KD-{$name}", 'name' => "Facility {$name}", 'lga_id' => $lgaId,
                'ward_id' => Rows::ward(['lga_id' => $lgaId, 'name' => "Ward {$name}"]),
                'ownership' => $owner, 'level' => $level,
            ]);
            $assessment = Rows::assessment(['round_id' => $f->q2, 'facility_id' => $f->facilities[$name], 'started_at' => $started, 'ended_at' => $started]);
            self::scores($assessment, $f->v2, $a, $c, $v);
        }

        // Noise: an older version's scores on the same assessment, and a closed earlier round.
        $f1q2 = (int) DB::table('assessments')->where('round_id', $f->q2)->where('facility_id', $f->facilities['F1'])->value('id');
        self::scores($f1q2, $f->v1, [1, 1, 1], [1, 1, 1], [1, 1, 1]);
        $f1q1 = Rows::assessment(['round_id' => $f->q1, 'facility_id' => $f->facilities['F1'], 'started_at' => '2026-02-10 09:00:00+01', 'ended_at' => '2026-02-10 10:00:00+01']);
        self::scores($f1q1, $f->v1, [10, 10, 10], [10, 10, 10], [10, 10, 10]);

        return $f;
    }

    /**
     * Nine assessment_scores rows in one insert.
     *
     * @param  array{0: float|int, 1: float|int, 2: float|int}  $availability
     * @param  array{0: float|int, 1: float|int, 2: float|int}  $consistency
     * @param  array{0: float|int, 1: float|int, 2: float|int}  $validity
     */
    public static function scores(int $assessmentId, int $versionId, array $availability, array $consistency, array $validity): void
    {
        $rows = [];
        foreach (['availability' => $availability, 'consistency' => $consistency, 'validity' => $validity] as $dimension => $slots) {
            foreach ($slots as $i => $score) {
                $rows[] = [
                    'assessment_id' => $assessmentId, 'dimension' => $dimension, 'month_slot' => $i + 1,
                    'score' => $score, 'rule_version_id' => $versionId, 'computed_at' => now(),
                ];
            }
        }
        DB::table('assessment_scores')->insert($rows);
    }
}
