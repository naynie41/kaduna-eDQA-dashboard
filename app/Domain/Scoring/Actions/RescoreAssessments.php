<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Reporting\Queries\FacilityRoundOverallsQuery;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreCard;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\IncompleteAssessmentException;
use App\Domain\Scoring\Models\RescoreRun;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Scoring\ScoreCalculator;
use App\Domain\Scoring\ScoreFlags;
use App\Support\Audit\AuditLog;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One chunk of a rescore (ARCHITECTURE.md §7), in one transaction so a failed chunk leaves
 * nothing behind and can be retried:
 *
 * 1. reads the chunk's item responses and scores them with ScoreCalculator under the run's rule
 *    version (stored raw choices mapped through its choice_map, Q-25);
 * 2. upserts assessment_scores on (assessment, dimension, month, version) in one statement,
 *    touching only rows whose value changes, so a repeat rescore writes nothing (not even
 *    computed_at); each row it inserts or changes is audited;
 * 3. recomputes the flags that depend on scores (SCORE_JUMP, ALL_PERFECT, SLOT_ALL_NA notes),
 *    keeping other soft flags and never raising again a flag an administrator overrode;
 * 4. counts the chunk once on the run, however often it is delivered.
 *
 * An assessment that can no longer be scored (a whole dimension N/A) loses its rows for this
 * version, audited, and is counted as unscorable: no stale score stays readable.
 */
final class RescoreAssessments
{
    private const SCORE_CODES = [ScoreFlags::SCORE_JUMP, ScoreFlags::ALL_PERFECT];

    private const NOTE_PREFIX = 'SLOT_ALL_NA:';

    /** @param  array<string, string>  $rules  edqa.validation.rules, in pipeline order */
    public function __construct(
        private readonly ScoreCalculator $calculator,
        private readonly FacilityRoundOverallsQuery $overalls,
        #[Config('edqa.validation.soft_rules.score_jump_points')] private readonly int|float $maxJump,
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    /** @param  list<int>  $assessmentIds */
    public function execute(int $runId, int $chunk, array $assessmentIds): void
    {
        DB::transaction(function () use ($runId, $chunk, $assessmentIds): void {
            if (DB::table('rescore_runs')->where('id', $runId)->whereRaw('? = ANY(completed_chunks)', [$chunk])->exists()) {
                return; // delivered again after it committed
            }

            $run = RescoreRun::query()->findOrFail($runId);
            $round = Round::query()->findOrFail($run->round_id);

            $assessments = Assessment::query()
                ->whereIn('id', $assessmentIds)
                ->whereIn('status', ['accepted', 'flagged'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $result = $this->score($assessments, $round, $run->rule_version_id);

            DB::update(<<<'SQL'
                UPDATE rescore_runs
                SET processed_assessments = processed_assessments + ?, changed_scores = changed_scores + ?,
                    changed_flags = changed_flags + ?, unscorable = unscorable + ?,
                    completed_chunks = array_append(completed_chunks, ?)
                WHERE id = ? AND NOT (? = ANY(completed_chunks))
                SQL, [count($assessmentIds), $result['scores'], $result['flags'], count($result['unscorable']), $chunk, $runId, $chunk]);
        });
    }

    /**
     * Steps 1 to 3 for assessments the caller has locked, in the caller's transaction: scores
     * from their item responses through ScoreCalculator, written and audited, and the
     * score-based flags. A run's chunk uses it, and so does CorrectAssessment for the one
     * assessment it corrected, so a corrected assessment is scored exactly as a rescore would.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @return array{scores: int, flags: int, unscorable: list<int>}
     */
    public function score(Collection $assessments, Round $round, int $ruleVersionId): array
    {
        $rules = RuleConfig::fromVersion(ScoringRuleVersion::query()->findOrFail($ruleVersionId));

        $cards = [];
        $unscorable = [];
        foreach ($this->items($assessments->modelKeys(), $rules) as $assessmentId => $items) {
            try {
                $cards[$assessmentId] = $this->calculator->calculate($items, $rules);
            } catch (IncompleteAssessmentException) {
                $unscorable[] = $assessmentId;
            }
        }
        foreach ($assessments as $assessment) {
            if (! isset($cards[$assessment->id]) && ! in_array($assessment->id, $unscorable, true)) {
                $unscorable[] = $assessment->id; // no items at all
            }
        }

        $changedScores = $this->writeScores($cards, $ruleVersionId) + $this->removeScores($unscorable, $ruleVersionId);

        return ['scores' => $changedScores, 'flags' => $this->updateFlags($assessments, $cards, $round), 'unscorable' => $unscorable];
    }

    /**
     * Item responses by assessment, as the calculator reads them.
     *
     * @param  list<int>  $assessmentIds
     * @return array<int, list<ItemResponseData>>
     */
    private function items(array $assessmentIds, RuleConfig $rules): array
    {
        $items = [];
        $rows = DB::table('item_responses')
            ->whereIn('assessment_id', $assessmentIds)
            ->orderBy('assessment_id')->orderBy('id')
            ->get(['assessment_id', 'dimension', 'month_slot', 'item_code', 'value', 'is_applicable']);

        foreach ($rows as $row) {
            $items[(int) $row->assessment_id][] = new ItemResponseData(
                Dimension::from((string) $row->dimension),
                (int) $row->month_slot,
                (string) $row->item_code,
                $rules->outcome((string) $row->value),
                (bool) $row->is_applicable,
            );
        }

        return $items;
    }

    /**
     * Inserts missing rows and updates changed ones; an unchanged row is not touched. Returns the
     * number of rows written, each audited.
     *
     * @param  array<int, ScoreCard>  $cards
     */
    private function writeScores(array $cards, int $ruleVersionId): int
    {
        if ($cards === []) {
            return 0;
        }

        $values = [];
        $bindings = [];
        foreach ($cards as $assessmentId => $card) {
            foreach ($card->slots as $dimension => $months) {
                foreach ($months as $month => $score) {
                    $values[] = '(?::bigint, ?::varchar, ?::smallint, ?::numeric(5, 2))';
                    array_push($bindings, $assessmentId, $dimension, $month, $score);
                }
            }
        }
        $values = implode(', ', $values);

        $written = DB::select(<<<SQL
            WITH incoming (assessment_id, dimension, month_slot, score) AS (
                VALUES {$values}
            ),
            previous AS (
                SELECT s.assessment_id, s.dimension, s.month_slot, s.score
                FROM assessment_scores s
                JOIN incoming i USING (assessment_id, dimension, month_slot)
                WHERE s.rule_version_id = ?
            ),
            written AS (
                INSERT INTO assessment_scores (assessment_id, dimension, month_slot, score, rule_version_id, computed_at)
                SELECT assessment_id, dimension, month_slot, score, ?::bigint, ?::timestamptz FROM incoming
                ON CONFLICT (assessment_id, dimension, month_slot, rule_version_id)
                DO UPDATE SET score = EXCLUDED.score, computed_at = EXCLUDED.computed_at
                WHERE assessment_scores.score IS DISTINCT FROM EXCLUDED.score
                RETURNING id, assessment_id, dimension, month_slot, score, (xmax = 0) AS inserted
            )
            SELECT w.id, w.dimension, w.month_slot, w.score::text AS score, w.inserted, p.score::text AS previous
            FROM written w
            LEFT JOIN previous p USING (assessment_id, dimension, month_slot)
            ORDER BY w.id
            SQL, [...$bindings, $ruleVersionId, $ruleVersionId, now()]);

        $this->audit(array_map(fn (object $row): array => (bool) $row->inserted
            ? ['id' => (int) $row->id, 'event' => 'created', 'properties' => ['attributes' => [
                'dimension' => (string) $row->dimension, 'month_slot' => (int) $row->month_slot,
                'score' => $row->score, 'rule_version_id' => $ruleVersionId,
            ]]]
            : ['id' => (int) $row->id, 'event' => 'updated', 'properties' => [
                'attributes' => ['score' => $row->score], 'old' => ['score' => $row->previous],
            ]], $written));

        return count($written);
    }

    /**
     * Removes this version's rows for assessments that can no longer be scored.
     *
     * @param  list<int>  $assessmentIds
     */
    private function removeScores(array $assessmentIds, int $ruleVersionId): int
    {
        if ($assessmentIds === []) {
            return 0;
        }

        $removed = DB::select(<<<'SQL'
            DELETE FROM assessment_scores
            WHERE assessment_id = ANY(?::bigint[]) AND rule_version_id = ?
            RETURNING id, dimension, month_slot, score::text AS score
            SQL, ['{'.implode(',', $assessmentIds).'}', $ruleVersionId]);

        $this->audit(array_map(fn (object $row): array => ['id' => (int) $row->id, 'event' => 'deleted', 'properties' => ['old' => [
            'dimension' => (string) $row->dimension, 'month_slot' => (int) $row->month_slot,
            'score' => $row->score, 'rule_version_id' => $ruleVersionId,
        ]]], $removed));

        return count($removed);
    }

    /**
     * Score audit entries in bulk, shaped as the model's own (Audited) entries are; a rescore can
     * write thousands, one insert per 500.
     *
     * @param  list<array{id: int, event: string, properties: array<string, mixed>}>  $entries
     */
    private function audit(array $entries): void
    {
        $subjectType = (new AssessmentScore)->getMorphClass();
        $context = AuditLog::requestContext();
        $now = now();

        foreach (array_chunk($entries, 500) as $part) {
            DB::table('activity_log')->insert(array_map(fn (array $entry): array => [
                'log_name' => 'default',
                'description' => $entry['event'],
                'subject_type' => $subjectType,
                'subject_id' => $entry['id'],
                'event' => $entry['event'],
                'properties' => json_encode([...$entry['properties'], ...$context], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ], $part));
        }
    }

    /**
     * New flags for each scored assessment: other soft flags kept, SCORE_JUMP and ALL_PERFECT
     * from the new card unless overridden, the all-N/A notes from the new card. Saved (and
     * audited) only when the set or the status changes. Returns the number saved.
     *
     * @param  Collection<int, Assessment>  $assessments
     * @param  array<int, ScoreCard>  $cards
     */
    private function updateFlags(Collection $assessments, array $cards, Round $round): int
    {
        $scored = $assessments->filter(fn (Assessment $a): bool => isset($cards[$a->id]));
        $previous = $this->previousOveralls($scored->pluck('facility_id')->unique()->values()->all(), $round);
        $overridden = $this->overridden($scored->modelKeys());
        $order = array_flip(array_keys($this->rules));

        $saved = 0;
        foreach ($scored as $assessment) {
            $card = $cards[$assessment->id];
            /** @var list<string> $flags */
            $flags = $assessment->flags;

            $soft = array_values(array_filter($flags, fn (string $f): bool => ! in_array($f, self::SCORE_CODES, true) && ! str_starts_with($f, self::NOTE_PREFIX)));
            if (ScoreFlags::jumped($card->overall, $previous[$assessment->facility_id] ?? null, $this->maxJump)) {
                $soft[] = ScoreFlags::SCORE_JUMP;
            }
            if (ScoreFlags::allPerfect($card)) {
                $soft[] = ScoreFlags::ALL_PERFECT;
            }
            $soft = array_values(array_unique(array_diff($soft, $overridden[$assessment->id] ?? [])));
            usort($soft, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

            $new = [...$soft, ...$card->exclusions];
            $status = $soft === [] ? 'accepted' : 'flagged';
            $sameSet = self::sorted($new) === self::sorted($flags);
            if ($sameSet && $status === $assessment->status) {
                continue;
            }

            $assessment->update(['flags' => $sameSet ? $flags : $new, 'status' => $status]);
            $saved++;
        }

        return $saved;
    }

    /**
     * Each facility's overall in its most recent round before this one, every round read under
     * its own version (D-32), as SCORE_JUMP reads it at ingestion.
     *
     * @param  list<int>  $facilityIds
     * @return array<int, float>
     */
    private function previousOveralls(array $facilityIds, Round $round): array
    {
        if ($facilityIds === []) {
            return [];
        }

        $sequence = [];
        foreach (Round::query()->get(['id', 'year', 'quarter']) as $r) {
            $sequence[$r->id] = $r->year * 4 + $r->quarter;
        }
        $current = $round->year * 4 + $round->quarter;

        $best = [];
        foreach ($this->overalls->forFacilities($facilityIds) as $row) {
            $seq = $sequence[$row['round_id']] ?? null;
            if ($seq === null || $seq >= $current) {
                continue;
            }
            if (! isset($best[$row['facility_id']]) || $seq > $best[$row['facility_id']]['seq']) {
                $best[$row['facility_id']] = ['seq' => $seq, 'overall' => $row['overall']];
            }
        }

        return array_map(fn (array $b): float => $b['overall'], $best);
    }

    /**
     * Soft flags an administrator overrode (OverrideSoftFlag's audit entries): they stay cleared.
     *
     * @param  list<int>  $assessmentIds
     * @return array<int, list<string>>
     */
    private function overridden(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }

        $codes = [];
        $rows = DB::table('activity_log')
            ->where('subject_type', (new Assessment)->getMorphClass())
            ->whereIn('subject_id', $assessmentIds)
            ->where('event', 'flag_overridden')
            ->get(['subject_id', DB::raw("properties->>'code' AS code")]);
        foreach ($rows as $row) {
            $codes[(int) $row->subject_id][] = (string) $row->code;
        }

        return $codes;
    }

    /**
     * @param  list<string>  $flags
     * @return list<string>
     */
    private static function sorted(array $flags): array
    {
        sort($flags);

        return $flags;
    }
}
