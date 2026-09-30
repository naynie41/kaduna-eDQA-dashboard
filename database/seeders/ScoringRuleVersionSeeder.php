<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Scoring rule version 1, published, in every environment (ARCHITECTURE.md §7): default bands,
 * every item weight 1 (unweighted), N/A excluded, and the yes/no/na choice map (§5.1).
 * created_by is null: it is seeded before any administrator exists. Idempotent: never
 * overwrites an existing version 1.
 */
final class ScoringRuleVersionSeeder extends Seeder
{
    public const CONFIG = [
        'bands' => ['strong' => 90, 'acceptable' => 80, 'review' => 70],
        'item_weights' => [],   // empty: every item weighs 1
        'na_policy' => 'exclude',
        'choice_map' => ['yes' => 'pass', 'no' => 'fail', 'na' => 'na'],
    ];

    public function run(): void
    {
        $now = now();

        DB::table('scoring_rule_versions')->insertOrIgnore([
            'version' => 1,
            'config' => json_encode(self::CONFIG, JSON_THROW_ON_ERROR),
            'created_by' => null,
            'published_at' => $now,
            'published_reason' => 'Initial scoring rules: default bands, unweighted, N/A excluded.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
