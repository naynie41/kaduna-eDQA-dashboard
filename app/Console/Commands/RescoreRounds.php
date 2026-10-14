<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\Enums\RescoreTrigger;
use App\Domain\Scoring\Exceptions\RescoreRefused;
use Illuminate\Console\Command;

/**
 * Starts a rescore of one round, or of every open round (ARCHITECTURE.md §7). The batch runs on
 * the 'rescore' queue; follow it on the admin page or GET /api/rescores/{batchId}. A closed
 * round is refused (D-32). Audited on the round as rescore_requested, source console.
 */
final class RescoreRounds extends Command
{
    protected $signature = 'edqa:rescore
        {round? : The round id}
        {--all-open : Every open round instead}
        {--reason= : Recorded with the request}';

    protected $description = 'Rescore a round (or every open round) from its item responses';

    public function handle(RescoreRound $rescore): int
    {
        $roundId = $this->argument('round');
        $allOpen = (bool) $this->option('all-open');
        if (($roundId === null) === ! $allOpen) {
            $this->error((string) __('scoring.rescore.choose'));

            return self::FAILURE;
        }

        if ($allOpen) {
            $rounds = Round::query()->where('status', RoundStatus::Open)->orderBy('id')->get();
            if ($rounds->isEmpty()) {
                $this->info((string) __('scoring.rescore.no_open_rounds'));

                return self::SUCCESS;
            }
        } else {
            $round = Round::query()->find((int) $roundId);
            if ($round === null) {
                $this->error((string) __('scoring.rescore.unknown_round', ['id' => (string) $roundId]));

                return self::FAILURE;
            }
            $rounds = collect([$round]);
        }

        $reason = $this->option('reason');
        $failed = false;
        foreach ($rounds as $round) {
            try {
                $run = $rescore->start($round, RescoreTrigger::Console, reason: is_string($reason) ? $reason : null);
            } catch (RescoreRefused $e) {
                $this->error($e->getMessage());
                $failed = true;

                continue;
            }

            $this->info((string) __('scoring.rescore.started', [
                'round' => (string) $round->id,
                'year' => (string) $round->year,
                'quarter' => (string) $round->quarter,
                'count' => (string) $run->total_assessments,
                'version' => (string) $run->rule_version_id,
                'batch' => (string) $run->batch_id,
                'run' => (string) $run->id,
            ]));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
