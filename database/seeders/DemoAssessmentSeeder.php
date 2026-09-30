<?php

declare(strict_types=1);

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * DEMO assessments for local and staging (and tests): never production.
 *
 * One accepted assessment per sampled facility per round, each with a synthetic submission
 * and item responses only. Scores are NOT seeded: scoring arrives in Phase 3 and derives them
 * from these items (CLAUDE.md hard rule 2).
 *
 * Items are tuned so pass rates approximate the published state means (CONVENTION.md §8):
 * Public ≈ 92 / 79 / 92, Private ≈ 75 / 62 / 84 (availability / consistency / validity). Each
 * item passes with probability (target + LGA lift + facility lift) / 100, so LGAs and
 * facilities differ realistically. Seeded, so every run produces the same data.
 */
final class DemoAssessmentSeeder extends Seeder
{
    public const TARGETS = [
        'public' => ['availability' => 92, 'consistency' => 79, 'validity' => 92],
        'private' => ['availability' => 75, 'consistency' => 62, 'validity' => 84],
    ];

    /** Share of facilities assessed per round. */
    private const SAMPLE_RATE = 0.2;

    /** Applicable items per dimension-month cell. */
    private const ITEMS_PER_CELL = 10;

    /** Share of cells that also carry one N/A item (excluded from scoring). */
    private const NA_RATE = 0.1;

    private const LGA_LIFT_SD = 3.0;

    private const FACILITY_LIFT_SD = 4.0;

    private Randomizer $random;

    public function run(): void
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            throw new RuntimeException('DemoAssessmentSeeder only runs in local, staging or testing, never '.app()->environment().'.');
        }

        $this->random = new Randomizer(new Mt19937(20260930));

        $lgas = DB::table('lgas')->get(['id', 'name']);
        $facilities = DB::table('facilities')->join('lgas', 'lgas.id', '=', 'facilities.lga_id')
            ->orderBy('facilities.id')
            ->get(['facilities.id', 'facilities.code', 'facilities.lga_id', 'facilities.ownership', 'lgas.name as lga', 'facilities.ward_id']);
        $rounds = DB::table('rounds')->orderBy('year')->orderBy('quarter')->get();

        $lgaLift = $lgas->mapWithKeys(fn (object $l): array => [$l->id => $this->gauss(self::LGA_LIFT_SD)]);
        $facilityLift = $facilities->mapWithKeys(fn (object $f): array => [$f->id => $this->gauss(self::FACILITY_LIFT_SD)]);

        $visits = [];
        foreach ($rounds as $round) {
            foreach ($facilities as $facility) {
                if ($this->random->getFloat(0, 1) < self::SAMPLE_RATE) {
                    $visits[] = [$round, $facility];
                }
            }
        }

        $submissionIds = $this->insertSubmissions($visits);
        $assessmentIds = $this->insertAssessments($visits, $submissionIds);
        $this->insertItems($visits, $assessmentIds, $lgaLift->all(), $facilityLift->all());
    }

    /**
     * @param  list<array{0: object, 1: object}>  $visits
     * @return array<string, int> instance_id => submission id
     */
    private function insertSubmissions(array $visits): array
    {
        $rows = [];
        foreach ($visits as [$round, $facility]) {
            $instanceId = $this->instanceId($round, $facility);
            $visit = $this->visitDate($round, $facility);
            $rows[] = [
                'instance_id' => $instanceId,
                'form_id' => 'dqa',
                'form_version' => '2026.1',
                'payload' => json_encode([
                    '__id' => $instanceId,
                    'meta' => ['instanceID' => $instanceId],
                    'facility' => ['facility_code' => $facility->code, 'lga' => $facility->lga],
                    'round_year' => $round->year,
                    'round_quarter' => $round->quarter,
                    'demo' => true,
                ], JSON_THROW_ON_ERROR),
                'submitted_at' => $visit->addHours(3),
                'received_at' => $visit->addHours(3)->addMinutes(10),
                'processed_at' => $visit->addHours(3)->addMinutes(11),
                'status' => 'accepted',
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('submissions')->insert($chunk);
        }

        return DB::table('submissions')->where('instance_id', 'like', 'uuid:demo-%')->pluck('id', 'instance_id')->all();
    }

    /**
     * @param  list<array{0: object, 1: object}>  $visits
     * @param  array<string, int>  $submissionIds
     * @return array<int, int> submission id => assessment id
     */
    private function insertAssessments(array $visits, array $submissionIds): array
    {
        $now = now();
        $rows = [];
        foreach ($visits as [$round, $facility]) {
            $start = $this->visitDate($round, $facility);
            $rows[] = [
                'round_id' => $round->id,
                'facility_id' => $facility->id,
                'submission_id' => $submissionIds[$this->instanceId($round, $facility)],
                'assessor_name' => 'Demo assessor '.($facility->lga_id % 7 + 1),
                'device_id' => 'collect:demo'.($facility->lga_id % 7 + 1),
                'started_at' => $start,
                'ended_at' => $start->addMinutes($this->random->getInt(45, 150)),
                'status' => 'accepted',
                'flags' => '[]',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('assessments')->insert($chunk);
        }

        return DB::table('assessments')->whereIn('submission_id', array_values($submissionIds))->pluck('id', 'submission_id')->all();
    }

    /**
     * @param  list<array{0: object, 1: object}>  $visits
     * @param  array<int, int>  $assessmentIds
     * @param  array<int, float>  $lgaLift
     * @param  array<int, float>  $facilityLift
     */
    private function insertItems(array $visits, array $assessmentIds, array $lgaLift, array $facilityLift): void
    {
        $submissionIds = DB::table('submissions')->where('instance_id', 'like', 'uuid:demo-%')->pluck('id', 'instance_id')->all();
        $lines = [];

        foreach ($visits as [$round, $facility]) {
            $assessmentId = $assessmentIds[$submissionIds[$this->instanceId($round, $facility)]];

            foreach (self::TARGETS[$facility->ownership] as $dimension => $target) {
                $passRate = max(0.0, min(1.0, ($target + $lgaLift[$facility->lga_id] + $facilityLift[$facility->id]) / 100));

                foreach ([1, 2, 3] as $slot) {
                    for ($i = 1; $i <= self::ITEMS_PER_CELL; $i++) {
                        $lines[] = $this->itemLine($assessmentId, $dimension, $slot, "item_{$i}", $this->random->getFloat(0, 1) < $passRate ? 'yes' : 'no');
                    }
                    if ($this->random->getFloat(0, 1) < self::NA_RATE) {
                        $lines[] = $this->itemLine($assessmentId, $dimension, $slot, 'item_na', 'na');
                    }
                }
            }
        }

        // ~160,000 rows: Postgres COPY is far faster than multi-row INSERTs, which keeps
        // `make fresh` under a minute.
        foreach (array_chunk($lines, 50_000) as $chunk) {
            $this->copyFromArray('item_responses', 'assessment_id,dimension,month_slot,item_code,value,is_applicable', $chunk);
        }
    }

    /** One tab-separated COPY line for item_responses. */
    private function itemLine(int $assessmentId, string $dimension, int $slot, string $code, string $value): string
    {
        return implode("\t", [$assessmentId, $dimension, $slot, $code, $value, $value === 'na' ? 'f' : 't']);
    }

    /**
     * @param  list<string>  $lines  tab-separated rows with no tabs, newlines or backslashes in values
     */
    private function copyFromArray(string $table, string $columns, array $lines): void
    {
        // PHP 8.3 (the image's version). On 8.4+ this becomes Pdo\Pgsql::copyFromArray().
        if (! DB::connection()->getPdo()->pgsqlCopyFromArray($table, $lines, "\t", '\\\\N', $columns)) {
            throw new RuntimeException("COPY into {$table} failed.");
        }
    }

    private function instanceId(object $round, object $facility): string
    {
        return "uuid:demo-{$round->year}q{$round->quarter}-{$facility->code}";
    }

    /** A morning inside the round's window, fixed per round and facility. */
    private function visitDate(object $round, object $facility): CarbonImmutable
    {
        $start = CarbonImmutable::parse($round->window_start, 'Africa/Lagos');
        $days = (int) $start->diffInDays(CarbonImmutable::parse($round->window_end, 'Africa/Lagos'));

        return $start->addDays(crc32("{$round->id}-{$facility->code}") % ($days + 1))->setTime(9, 30);
    }

    /** Normally distributed, mean 0 (Box–Muller). */
    private function gauss(float $sd): float
    {
        $u = max(1e-9, $this->random->getFloat(0, 1));
        $v = $this->random->getFloat(0, 1);

        return $sd * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }
}
