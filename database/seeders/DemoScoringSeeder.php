<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Reporting\RoundCache;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Enums\RescoreTrigger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEMO only (local, staging, tests): scores the demo item responses with the real rescore, so
 * the dashboard has numbers derived exactly as production derives them (CLAUDE.md hard rule 2).
 *
 * Rounds are scored oldest first, synchronously (the sync queue, whatever QUEUE_CONNECTION
 * says), so SCORE_JUMP finds each facility's earlier round already scored. A closed round cannot
 * be rescored (D-32), so each closed seed round is scored as it would have been while open, then
 * put back: closed, pinned to rule version 1, its closed_at kept. round_aggregates is refreshed
 * last and the report cache flushed.
 */
final class DemoScoringSeeder extends Seeder
{
    public function run(RescoreRound $rescore, CurrentRuleVersion $current, RoundCache $cache): void
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            throw new RuntimeException('DemoScoringSeeder only runs in local, staging or testing, never '.app()->environment().'.');
        }

        $v1 = (int) DB::table('scoring_rule_versions')->where('version', 1)->value('id');
        if ($v1 === 0 || $current->id() !== $v1) {
            throw new RuntimeException('DemoScoringSeeder expects rule version 1 to be the only published version; run it on a fresh database (make fresh).');
        }

        $queue = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            foreach (Round::query()->orderBy('year')->orderBy('quarter')->get() as $round) {
                $closed = $round->status === RoundStatus::Closed;
                if ($closed) {
                    DB::table('rounds')->where('id', $round->id)->update(['status' => RoundStatus::Open->value]);
                }

                $rescore->start($round->refresh(), RescoreTrigger::Console);

                if ($closed) {
                    DB::table('rounds')->where('id', $round->id)->update([
                        'status' => RoundStatus::Closed->value,
                        'rule_version_id' => $v1,
                        'closed_at' => $round->closed_at,
                    ]);
                }
            }
        } finally {
            config(['queue.default' => $queue]);
        }

        DB::select('SELECT refresh_round_aggregates()');
        $cache->flushAll();
    }
}
