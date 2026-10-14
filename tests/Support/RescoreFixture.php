<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Scoring\Enums\Dimension;
use Illuminate\Support\Facades\DB;

/**
 * A small world for rescore tests: one published version (v1), a closed 2026 Q1 pinned to it,
 * an open 2026 Q2, one LGA and ward, and helpers that write assessments with two items per
 * dimension × month (item_a, item_b), stored as raw choices (yes / no / na, Q-25).
 *
 * By default every slot is item_a yes, item_b no: 50 everywhere, overall 50.
 */
final class RescoreFixture
{
    public int $admin;

    public int $v1;

    public int $q1;

    public int $q2;

    public int $lga;

    public int $ward;

    public static function build(): self
    {
        $f = new self;
        $f->admin = Rows::user();
        $f->v1 = Rows::ruleVersion(['version' => 1, 'config' => json_encode(ReportingFixture::CONFIG), 'created_by' => $f->admin, 'published_at' => now()->subYear()]);
        $f->q1 = Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed', 'closed_at' => now(), 'rule_version_id' => $f->v1]);
        $f->q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
        $f->lga = Rows::lga(['code' => 'RSC', 'name' => 'Rescore']);
        $f->ward = Rows::ward(['lga_id' => $f->lga]);

        return $f;
    }

    public function facility(): int
    {
        return Rows::facility(['lga_id' => $this->lga, 'ward_id' => $this->ward]);
    }

    /**
     * @param  array<string, array{0: string, 1: string}>  $slots  "dimension.month" => [item_a, item_b]
     * @param  array<string, mixed>  $attributes
     */
    public function assessment(int $round, ?int $facility = null, array $slots = [], array $attributes = [], string $default = 'yes/no'): int
    {
        $id = Rows::assessment(['round_id' => $round, 'facility_id' => $facility ?? $this->facility(), ...$attributes]);
        [$a, $b] = explode('/', $default);

        $rows = [];
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $month) {
                $values = $slots["{$dimension->value}.{$month}"] ?? [$a, $b];
                foreach (['item_a' => $values[0], 'item_b' => $values[1]] as $code => $value) {
                    $rows[] = ['assessment_id' => $id, 'dimension' => $dimension->value, 'month_slot' => $month,
                        'item_code' => $code, 'value' => $value, 'is_applicable' => true];
                }
            }
        }
        DB::table('item_responses')->insert($rows);

        return $id;
    }

    /** Nine stored scores, as an earlier scoring would have left them. */
    public static function scores(int $assessment, int $version, float $score): void
    {
        $rows = [];
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $month) {
                $rows[] = ['assessment_id' => $assessment, 'dimension' => $dimension->value, 'month_slot' => $month,
                    'score' => $score, 'rule_version_id' => $version, 'computed_at' => now()];
            }
        }
        DB::table('assessment_scores')->insert($rows);
    }

    /**
     * Stored scores of a round, in key order, with the columns a rescore could touch.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(int $round, ?int $version = null): array
    {
        return DB::table('assessment_scores as s')
            ->join('assessments as a', 'a.id', '=', 's.assessment_id')
            ->where('a.round_id', $round)
            ->when($version !== null, fn ($q) => $q->where('s.rule_version_id', $version))
            ->orderBy('s.assessment_id')->orderBy('s.dimension')->orderBy('s.month_slot')->orderBy('s.rule_version_id')
            ->get(['s.id', 's.assessment_id', 's.dimension', 's.month_slot', 's.rule_version_id', 's.score', 's.computed_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }
}
