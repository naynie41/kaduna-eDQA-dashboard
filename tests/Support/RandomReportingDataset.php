<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * A reproducible random dataset for reconciling round_aggregates with the aggregators. The same
 * seed always builds the same data. Deliberately awkward:
 *
 * - 5 LGAs, two wards each; LGA "PRV" has private facilities only
 * - two rounds: Q1 closed (pinned to v1), Q2 open (current v2); most facilities visited in
 *   both (duplicate visits across rounds)
 * - scores with two decimals; about one slot in ten all N/A (NULL), and the odd whole
 *   dimension N/A, so some facility overalls are NULL
 * - noise the view must ignore: a withdrawn assessment, and scores under a draft version
 */
final class RandomReportingDataset
{
    public const LEVELS = ['primary', 'secondary', 'tertiary'];

    public int $q1;

    public int $q2;

    public static function build(int $seed, int $facilities = 36): self
    {
        mt_srand($seed);
        $d = new self;

        $v1 = Rows::ruleVersion(['version' => 1, 'config' => json_encode(ReportingFixture::CONFIG), 'published_at' => now()->subYear()]);
        $v2 = Rows::ruleVersion(['version' => 2, 'config' => json_encode(ReportingFixture::CONFIG), 'published_at' => now()]);
        $draft = Rows::ruleVersion(['version' => 3, 'config' => json_encode(ReportingFixture::CONFIG), 'published_at' => null]);
        $d->q1 = Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed', 'rule_version_id' => $v1]);
        $d->q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);

        $wards = [];
        foreach (['AAA', 'BBB', 'CCC', 'DDD', 'PRV'] as $code) {
            $lga = Rows::lga(['code' => $code, 'name' => "LGA {$code}"]);
            $wards[$code] = [Rows::ward(['lga_id' => $lga]), Rows::ward(['lga_id' => $lga])];
            $wards[$code]['lga'] = $lga;
        }

        $codes = array_keys($wards);
        for ($i = 1; $i <= $facilities; $i++) {
            $code = $codes[mt_rand(0, count($codes) - 1)];
            $owner = $code === 'PRV' ? 'private' : (mt_rand(1, 3) === 1 ? 'private' : 'public');
            $facility = Rows::facility([
                'code' => "RND-{$seed}-{$i}", 'lga_id' => $wards[$code]['lga'], 'ward_id' => $wards[$code][mt_rand(0, 1)],
                'ownership' => $owner, 'level' => self::LEVELS[mt_rand(0, 2)],
            ]);

            foreach ([[$d->q1, $v1, 60, '2026-02-'], [$d->q2, $v2, 85, '2026-05-']] as [$round, $version, $visitChance, $month]) {
                if (mt_rand(1, 100) > $visitChance) {
                    continue;
                }
                $day = sprintf('%s%02d 09:00:00+01', $month, mt_rand(1, 28));
                $assessment = Rows::assessment(['round_id' => $round, 'facility_id' => $facility, 'started_at' => $day, 'ended_at' => $day]);
                self::randomScores($assessment, $version);
                if (mt_rand(1, 10) === 1) {
                    self::randomScores($assessment, $draft);
                }
            }
        }

        // A withdrawn assessment: never counted, whatever its scores.
        $withdrawn = Rows::assessment(['round_id' => $d->q2, 'facility_id' => Rows::facility(['lga_id' => $wards['AAA']['lga'], 'ward_id' => $wards['AAA'][0]]), 'status' => 'withdrawn']);
        self::randomScores($withdrawn, $v2);

        return $d;
    }

    private static function randomScores(int $assessmentId, int $versionId): void
    {
        $rows = [];
        $deadDimension = mt_rand(1, 25) === 1 ? ['availability', 'consistency', 'validity'][mt_rand(0, 2)] : null;
        foreach (['availability', 'consistency', 'validity'] as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                $na = $dimension === $deadDimension || mt_rand(1, 10) === 1;
                $rows[] = [
                    'assessment_id' => $assessmentId, 'dimension' => $dimension, 'month_slot' => $slot,
                    'score' => $na ? null : mt_rand(0, 10_000) / 100, 'rule_version_id' => $versionId, 'computed_at' => now(),
                ];
            }
        }
        DB::table('assessment_scores')->insert($rows);
    }
}
