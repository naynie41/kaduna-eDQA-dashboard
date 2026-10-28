<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Reporting\RoundCache;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\DTOs\RuleConfig;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PERFORMANCE data for local and testing only (ARCHITECTURE.md §10 volume): `edqa:seed:perf`.
 *
 * Fills every quarter of the $years years ending at the latest round (20 rounds by default) with
 * one accepted assessment per active facility (~2,200 × 20 ≈ 44,000), each with a synthetic
 * submission and $itemsPerSlot item responses per dimension × month, then derives the nine slot
 * scores from those items and refreshes round_aggregates. Existing rounds and assessments are
 * kept: only missing (round, facility) pairs are added, so it runs on top of the demo data.
 *
 * Set-based SQL throughout (generate_series, INSERT … SELECT), seeded through setseed(), so a run
 * takes seconds to minutes rather than hours and gives the same data each time.
 *
 * Scores are derived from the generated items in SQL with ScoreCalculator's formula:
 * passed / applicable × 100 per slot, N/A out of both. That equals the calculator only for an
 * unweighted rule version whose choice map is yes → pass, no → fail, na → na, so any other
 * current version is refused (use `edqa:rescore` then). PerformanceSeederTest re-scores a sample
 * with the real ScoreCalculator to prove it. Bulk SQL bypasses the audit log: synthetic local data,
 * never production.
 */
final class PerformanceSeeder extends Seeder
{
    public int $years = 5;

    public int $itemsPerSlot = 10;

    public float $seed = 0.42;

    /** @var list<int> ids of the rounds that were filled */
    public array $rounds = [];

    public int $created = 0;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The performance dataset is for local and testing only.');
        }

        [$versionId, $rules] = $this->ruleVersion();
        $this->rounds = $this->ensureRounds($versionId);
        $before = (int) DB::table('assessments')->count();

        DB::statement('SELECT setseed(?)', [$this->seed]);
        DB::statement('DROP TABLE IF EXISTS perf_pairs');
        DB::statement(<<<'SQL'
            CREATE TEMP TABLE perf_pairs AS
            SELECT r.id AS round_id, f.id AS facility_id, r.window_start,
                -- each facility has its own quality, a little lower in private ones; each visit drifts
                least(0.99, greatest(0.30, 0.86 - CASE WHEN f.ownership = 'private' THEN 0.10 ELSE 0 END
                    + (abs(hashtext(f.code)) % 100 - 50) / 400.0 + (random() - 0.5) * 0.10)) AS quality
            FROM rounds r
            CROSS JOIN facilities f
            WHERE r.id = ANY(?::bigint[]) AND f.is_active
                AND NOT EXISTS (SELECT 1 FROM assessments a WHERE a.round_id = r.id AND a.facility_id = f.id)
            SQL, ['{'.implode(',', $this->rounds).'}']);

        DB::statement(<<<'SQL'
            INSERT INTO submissions (source_id, external_id, instance_id, form_ref, form_version, payload, submitted_at, received_at, processed_at, status)
            SELECT (SELECT id FROM submission_sources WHERE key = 'odk'), 'uuid:perf-' || round_id || '-' || facility_id,
                'uuid:perf-' || round_id || '-' || facility_id, 'dqa', '2026.1', '{"perf": true}'::jsonb, now(), now(), now(), 'accepted'
            FROM perf_pairs
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO assessments (round_id, facility_id, submission_id, assessor_name, device_id, started_at, ended_at, status, flags, created_at, updated_at)
            SELECT p.round_id, p.facility_id, s.id, 'Perf assessor ' || (p.facility_id % 40 + 1), NULL,
                v.started_at, v.started_at + interval '1 hour', 'accepted', '[]'::jsonb, now(), now()
            FROM perf_pairs p
            JOIN submissions s ON s.instance_id = 'uuid:perf-' || p.round_id || '-' || p.facility_id
            CROSS JOIN LATERAL (SELECT (p.window_start + (floor(random() * 80))::int + time '09:00') AT TIME ZONE ? AS started_at) v
            SQL, [(string) config('app.timezone')]);

        // Items: the first of each slot is always answered, so no dimension is all N/A.
        DB::statement(<<<'SQL'
            INSERT INTO item_responses (assessment_id, dimension, month_slot, item_code, value, is_applicable)
            SELECT a.id, d.dimension, m.slot, 'item_' || n.n,
                CASE WHEN n.n > 1 AND random() < 0.05 THEN 'na' WHEN random() < p.quality * d.lift THEN 'yes' ELSE 'no' END,
                true
            FROM perf_pairs p
            JOIN assessments a ON a.round_id = p.round_id AND a.facility_id = p.facility_id
            CROSS JOIN (VALUES ('availability', 1.06), ('consistency', 0.90), ('validity', 1.04)) AS d (dimension, lift)
            CROSS JOIN generate_series(1, 3) AS m (slot)
            CROSS JOIN generate_series(1, ?) AS n (n)
            SQL, [$this->itemsPerSlot]);

        // ScoreCalculator's slot score: passed / applicable × 100, N/A excluded from both; under
        // the version each round is read with (D-32: a closed round's pin, else the current one).
        $pass = array_keys($rules->choiceMap, 'pass', true);
        DB::statement(<<<'SQL'
            INSERT INTO assessment_scores (assessment_id, dimension, month_slot, score, rule_version_id, computed_at)
            SELECT i.assessment_id, i.dimension, i.month_slot,
                (count(*) FILTER (WHERE i.value = ANY(?::text[])))::numeric
                    / nullif(count(*) FILTER (WHERE i.is_applicable AND i.value <> 'na'), 0) * 100,
                CASE WHEN r.status = 'closed' THEN r.rule_version_id ELSE ? END, now()
            FROM item_responses i
            JOIN assessments a ON a.id = i.assessment_id
            JOIN perf_pairs p ON p.round_id = a.round_id AND p.facility_id = a.facility_id
            JOIN rounds r ON r.id = a.round_id
            GROUP BY i.assessment_id, i.dimension, i.month_slot, r.status, r.rule_version_id
            SQL, ['{'.implode(',', $pass).'}', $versionId]);

        DB::statement('DROP TABLE perf_pairs');
        foreach (['submissions', 'assessments', 'item_responses', 'assessment_scores'] as $table) {
            DB::statement("ANALYZE {$table}");
        }
        DB::select('SELECT refresh_round_aggregates()');
        app(RoundCache::class)->flushAll();

        $this->created = (int) DB::table('assessments')->count() - $before;
    }

    /** @return array{0: int, 1: RuleConfig} */
    private function ruleVersion(): array
    {
        $current = app(CurrentRuleVersion::class);
        $id = $current->id();
        $rules = $current->config();
        if ($id === null || $rules === null) {
            throw new RuntimeException('No published scoring rule version: seed one first.');
        }

        // The generated answers are yes / no / na: each must map as the SQL below assumes.
        $standard = ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'];
        $mapped = array_filter($standard, fn (string $outcome, string $choice): bool => ($rules->choiceMap[$choice] ?? null) === $outcome, ARRAY_FILTER_USE_BOTH);
        if ($rules->itemWeights !== [] || count($mapped) !== count($standard)) {
            throw new RuntimeException('The current rule version is weighted or maps choices differently: score with edqa:rescore instead.');
        }

        return [$id, $rules];
    }

    /**
     * The quarters of the $years years ending at the latest round (or this quarter), created when
     * missing: closed and pinned to the current version, except a new latest one, which is open.
     *
     * @return list<int> round ids, oldest first
     */
    private function ensureRounds(int $versionId): array
    {
        $latest = DB::table('rounds')->orderByDesc('year')->orderByDesc('quarter')->first(['year', 'quarter']);
        $now = CarbonImmutable::now();
        $year = $latest === null ? $now->year : (int) $latest->year;
        $quarter = $latest === null ? $now->quarter : (int) $latest->quarter;

        $ids = [];
        for ($i = $this->years * 4 - 1; $i >= 0; $i--) {
            $index = $year * 4 + $quarter - 1 - $i;
            $y = intdiv($index, 4);
            $q = $index % 4 + 1;
            $existing = DB::table('rounds')->where('year', $y)->where('quarter', $q)->value('id');
            if ($existing !== null) {
                $ids[] = (int) $existing;

                continue;
            }

            $start = CarbonImmutable::create($y, 3 * $q - 2, 1);
            $end = $start->addMonths(3)->subDay();
            $open = $i === 0;
            $ids[] = (int) DB::table('rounds')->insertGetId([
                'year' => $y, 'quarter' => $q,
                'window_start' => $start->toDateString(), 'window_end' => $end->toDateString(),
                'status' => $open ? 'open' : 'closed',
                'closed_at' => $open ? null : $end->addDays(21)->setTime(17, 0),
                'rule_version_id' => $open ? null : $versionId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    }
}
