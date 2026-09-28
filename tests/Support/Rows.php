<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Valid rows inserted with the raw query builder, for constraint tests that must bypass models
 * and factories. Each call gets unique names and codes; overrides replace any column.
 */
final class Rows
{
    private static int $sequence = 0;

    /** @param array<string, mixed> $overrides */
    public static function lga(array $overrides = []): int
    {
        $n = self::next();

        return DB::table('lgas')->insertGetId([
            'name' => "Test LGA {$n}",
            'code' => "LGA{$n}",
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function ward(array $overrides = []): int
    {
        $n = self::next();

        return DB::table('wards')->insertGetId([
            'lga_id' => $overrides['lga_id'] ?? self::lga(),
            'name' => "Test Ward {$n}",
            'code' => "WRD{$n}",
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function facility(array $overrides = []): int
    {
        $n = self::next();
        $lgaId = $overrides['lga_id'] ?? self::lga();

        return DB::table('facilities')->insertGetId([
            'code' => "FAC{$n}",
            'name' => "Test Facility {$n}",
            'lga_id' => $lgaId,
            'ward_id' => $overrides['ward_id'] ?? self::ward(['lga_id' => $lgaId]),
            'level' => 'primary',
            'ownership' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function round(array $overrides = []): int
    {
        $year = 2000 + self::next();

        return DB::table('rounds')->insertGetId([
            'year' => $year,
            'quarter' => 1,
            'window_start' => "{$year}-01-01",
            'window_end' => "{$year}-03-31",
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function user(array $overrides = []): int
    {
        $n = self::next();

        return DB::table('users')->insertGetId([
            'name' => "Admin {$n}",
            'email' => "admin{$n}@example.test",
            'password' => 'hash',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function submission(array $overrides = []): int
    {
        $n = self::next();

        return DB::table('submissions')->insertGetId([
            'instance_id' => "uuid:test-{$n}",
            'form_id' => 'dqa',
            'form_version' => '2026.1',
            'payload' => json_encode(['facility' => ['facility_code' => "FAC{$n}"]]),
            'submitted_at' => now(),
            'received_at' => now(),
            'status' => 'received',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function ruleVersion(array $overrides = []): int
    {
        return DB::table('scoring_rule_versions')->insertGetId([
            'version' => self::next(),
            'config' => json_encode(['bands' => ['strong' => 90]]),
            'created_by' => $overrides['created_by'] ?? self::user(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function assessment(array $overrides = []): int
    {
        return DB::table('assessments')->insertGetId([
            'round_id' => $overrides['round_id'] ?? self::round(),
            'facility_id' => $overrides['facility_id'] ?? self::facility(),
            'submission_id' => $overrides['submission_id'] ?? self::submission(),
            'assessor_name' => 'Assessor',
            'started_at' => '2026-05-04 09:00:00+01',
            'ended_at' => '2026-05-04 10:00:00+01',
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function itemResponse(array $overrides = []): int
    {
        return DB::table('item_responses')->insertGetId([
            'assessment_id' => $overrides['assessment_id'] ?? self::assessment(),
            'dimension' => 'availability',
            'month_slot' => 1,
            'item_code' => 'nhmis_summary',
            'value' => 'yes',
            'is_applicable' => true,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function score(array $overrides = []): int
    {
        return DB::table('assessment_scores')->insertGetId([
            'assessment_id' => $overrides['assessment_id'] ?? self::assessment(),
            'dimension' => 'availability',
            'month_slot' => 1,
            'score' => 87.5,
            'rule_version_id' => $overrides['rule_version_id'] ?? self::ruleVersion(),
            'computed_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function quarantined(array $overrides = []): int
    {
        $submissionId = $overrides['submission_id'] ?? self::submission();

        return DB::table('quarantined_records')->insertGetId([
            'submission_id' => $submissionId,
            'instance_id' => "uuid:quarantined-{$submissionId}",
            'payload' => json_encode(['lga' => null]),
            'failures' => json_encode([['code' => 'LGA_UNKNOWN', 'severity' => 'hard', 'detail' => 'lga was blank']]),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function planAction(array $overrides = []): int
    {
        return DB::table('plan_actions')->insertGetId([
            'round_id' => $overrides['round_id'] ?? self::round(),
            'lga_id' => $overrides['lga_id'] ?? self::lga(),
            'dimension' => 'availability',
            'action_text' => 'Supportive supervision on NHMIS summary forms',
            'assigned_to' => 'LGA M&E officer',
            'due_date' => '2026-07-31',
            'status' => 'not_started',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function pullRun(array $overrides = []): int
    {
        return DB::table('odk_pull_runs')->insertGetId([
            'trigger_type' => 'scheduled',
            'started_at' => now(),
            ...$overrides,
        ]);
    }

    private static function next(): int
    {
        return ++self::$sequence;
    }
}
